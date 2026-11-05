// Comando heimdeye-agent: l'agent que l'alumne instal·la a la seva instància.
//
// Model pull: l'agent es registra a HeimdEye i, amb long polling, recull la feina pendent,
// l'executa en local (bash, com l'usuari que corre l'agent: root per defecte, o el de --user al
// servei) i en retorna el codi de sortida i la sortida. No obre cap port: tota la comunicació surt
// de la instància cap a HeimdEye, xifrada per HTTPS.
//
// Un sol binari estàtic, sense dependències (només la biblioteca estàndard de Go). De moment
// Linux; es pot compilar per a altres sistemes sense canvis.
//
//	heimdeye-agent run     --url https://heimdeye... --token XXX --hostname web
//	heimdeye-agent install --url https://heimdeye... --token XXX --hostname web [--user alumne]
package main

import (
	"bytes"
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"os/user"
	"strings"
	"syscall"
	"time"
)

const version = "1.3.0"

type config struct {
	url      string
	token    string
	hostname string
	user     string
}

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}
	cmd := os.Args[1]
	fs := flag.NewFlagSet(cmd, flag.ExitOnError)
	cfg := config{}
	fs.StringVar(&cfg.url, "url", "", "URL base de HeimdEye (p. ex. https://heimdeye.exemple.cat)")
	fs.StringVar(&cfg.token, "token", "", "token de l'alumne")
	fs.StringVar(&cfg.hostname, "hostname", "", "nom per identificar aquesta instància (fa de rol: web, bd...)")
	fs.StringVar(&cfg.user, "user", "", "(install) usuari del sistema sota el qual corre el servei, per heretar el seu entorn i credencials (p. ex. l'AWS CLI d'una WSL). Per defecte, l'usuari que ha llançat sudo; --user root el força a root")
	_ = fs.Parse(os.Args[2:])

	switch cmd {
	case "run":
		mustConfig(cfg)
		if err := run(cfg); err != nil {
			fmt.Fprintln(os.Stderr, "error:", err)
			os.Exit(1)
		}
	case "install":
		mustConfig(cfg)
		if err := install(cfg); err != nil {
			fmt.Fprintln(os.Stderr, "error:", err)
			os.Exit(1)
		}
	case "version", "-v", "--version":
		fmt.Println("heimdeye-agent", version)
	default:
		usage()
		os.Exit(2)
	}
}

func usage() {
	fmt.Fprintln(os.Stderr, `heimdeye-agent — agent de les instàncies dels alumnes

Ús:
  heimdeye-agent run     --url URL --token TOKEN --hostname NOM
  heimdeye-agent install --url URL --token TOKEN --hostname NOM [--user USUARI]
  heimdeye-agent version`)
}

func mustConfig(cfg config) {
	if cfg.url == "" || cfg.token == "" || cfg.hostname == "" {
		fmt.Fprintln(os.Stderr, "calen --url, --token i --hostname")
		os.Exit(2)
	}
}

// run registra la instància i recull feina fins que es rep SIGINT/SIGTERM.
func run(cfg config) error {
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	client := &http.Client{Timeout: 60 * time.Second}
	if err := register(ctx, client, cfg); err != nil {
		return fmt.Errorf("no s'ha pogut registrar: %w", err)
	}
	fmt.Printf("registrat com a %q a %s; esperant feina...\n", cfg.hostname, cfg.url)

	for ctx.Err() == nil {
		task, err := poll(ctx, client, cfg)
		if err != nil {
			if ctx.Err() != nil {
				break
			}
			fmt.Fprintln(os.Stderr, "poll:", err)
			sleep(ctx, 5*time.Second)
			continue
		}
		if task == nil {
			continue // long poll buit: es torna a preguntar
		}
		if task.Console {
			fmt.Printf("consola %s oberta\n", string(task.ID))
			runConsole(ctx, client, cfg, task.ID)
			fmt.Printf("consola %s tancada\n", string(task.ID))
			continue
		}
		exit, output, durationMs, timedOut := execute(ctx, task)
		if err := postResult(ctx, client, cfg, task.ID, exit, output, durationMs, timedOut); err != nil {
			fmt.Fprintln(os.Stderr, "result:", err)
		} else {
			fmt.Printf("feina %s executada (exit %d)\n", string(task.ID), exit)
		}
	}
	return nil
}

// L'identificador el tracta HeimdEye: pot ser un enter (acció) o una cadena "check:<id>" (check).
// L'agent no l'interpreta; el torna tal qual al resultat (json.RawMessage). Si Console és cert, la
// feina és una sessió de consola interactiva i s'obre un bash persistent (veg. runConsole).
type task struct {
	ID      json.RawMessage   `json:"id"`
	Code    string            `json:"code"`
	Env     map[string]string `json:"env"`
	Timeout int               `json:"timeout"`
	Console bool              `json:"console"`
}

func register(ctx context.Context, client *http.Client, cfg config) error {
	body := map[string]string{
		"hostname":   cfg.hostname,
		"ip_privada": privateIP(),
		"ip_publica": publicIP(ctx, client),
		"sistema":    osName(),
		"versio":     version,
	}
	resp, err := postJSON(ctx, client, cfg, "/api/agent/register", body)
	if err != nil {
		return err
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("estat %d", resp.StatusCode)
	}
	return nil
}

// poll manté una petició oberta fins que HeimdEye torna feina o venç el temps d'espera del servidor.
func poll(ctx context.Context, client *http.Client, cfg config) (*task, error) {
	u := strings.TrimRight(cfg.url, "/") + "/api/agent/poll?hostname=" + urlQueryEscape(cfg.hostname)
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+cfg.token)
	req.Header.Set("Accept", "application/json")
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("estat %d", resp.StatusCode)
	}
	var out struct {
		Task *task `json:"task"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return nil, err
	}
	return out.Task, nil
}

// execute corre el codi amb bash a la instància, en memòria (per stdin, sense desar-lo al disc),
// amb les variables d'entorn que envia HeimdEye, i en retorna el codi de sortida i la sortida.
func execute(parent context.Context, t *task) (exit int, output string, durationMs int, timedOut bool) {
	timeout := t.Timeout
	if timeout <= 0 {
		timeout = 60
	}
	ctx, cancel := context.WithTimeout(parent, time.Duration(timeout)*time.Second)
	defer cancel()

	cmd := exec.CommandContext(ctx, "bash", "-s")
	cmd.Env = append(baseEnv(), flatten(t.Env)...)
	// Igual que l'executor local de HeimdEye: el codi dins un bloc amb stdin a /dev/null.
	cmd.Stdin = strings.NewReader("{\n" + t.Code + "\n} </dev/null\n")
	var buf bytes.Buffer
	cmd.Stdout = &buf
	cmd.Stderr = &buf

	start := time.Now()
	err := cmd.Run()
	durationMs = int(time.Since(start).Milliseconds())
	if ctx.Err() == context.DeadlineExceeded {
		return 124, buf.String() + "\n(temps màxim superat)", durationMs, true
	}
	if err != nil {
		if ee, ok := err.(*exec.ExitError); ok {
			exit = ee.ExitCode()
		} else {
			exit = 1
			buf.WriteString("\n" + err.Error())
		}
	}
	return exit, buf.String(), durationMs, false
}

func postResult(ctx context.Context, client *http.Client, cfg config, id json.RawMessage, exit int, output string, durationMs int, timedOut bool) error {
	resp, err := postJSON(ctx, client, cfg, "/api/agent/result", map[string]any{
		"task_id":     id,
		"exit_code":   exit,
		"output":      output,
		"duration_ms": durationMs,
		"timed_out":   timedOut,
	})
	if err != nil {
		return err
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("estat %d", resp.StatusCode)
	}
	return nil
}

// runConsole obre un bash persistent per a una sessió de consola i el connecta amb HeimdEye: la
// sortida del bash es va enviant (push) i l'entrada que escriu el professor es va recollint (pull,
// long poll). El directori i les variables es mantenen entre comandes. Es tanca quan la sessió es
// tanca des de HeimdEye o quan el bash acaba. Cap port: tot surt de l'agent cap a HeimdEye.
func runConsole(parent context.Context, client *http.Client, cfg config, rawID json.RawMessage) {
	var token string
	if err := json.Unmarshal(rawID, &token); err != nil {
		return
	}
	id := strings.TrimPrefix(token, "console:")

	ctx, cancel := context.WithCancel(parent)
	defer cancel()

	cmd := exec.Command("bash")
	cmd.Env = baseEnv()
	stdin, err := cmd.StdinPipe()
	if err != nil {
		return
	}
	pr, pw := io.Pipe()
	cmd.Stdout, cmd.Stderr = pw, pw
	if err := cmd.Start(); err != nil {
		pushConsole(ctx, client, cfg, id, "no s'ha pogut obrir bash: "+err.Error()+"\n")
		return
	}

	// Quan el bash acaba, tanquem el pipe (EOF per al lector) i avortem la sessió.
	go func() {
		_ = cmd.Wait()
		_ = pw.Close()
		cancel()
	}()

	// Sortida del bash -> HeimdEye.
	go func() {
		buf := make([]byte, 4096)
		for {
			n, rerr := pr.Read(buf)
			if n > 0 {
				if closed := pushConsole(ctx, client, cfg, id, string(buf[:n])); closed {
					cancel()
					return
				}
			}
			if rerr != nil {
				return
			}
		}
	}()

	// Entrada del professor -> bash.
	for ctx.Err() == nil {
		closed, input, perr := pullConsole(ctx, client, cfg, id)
		if perr != nil {
			if ctx.Err() != nil {
				break
			}
			sleep(ctx, 2*time.Second)
			continue
		}
		if closed {
			break
		}
		if input != "" {
			if _, werr := io.WriteString(stdin, input); werr != nil {
				break
			}
		}
	}
	_ = stdin.Close()
	cancel()
}

// pullConsole fa long poll de l'entrada pendent de la sessió; retorna també si s'ha tancat.
func pullConsole(ctx context.Context, client *http.Client, cfg config, id string) (closed bool, input string, err error) {
	u := strings.TrimRight(cfg.url, "/") + "/api/agent/console/" + id + "/pull"
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return false, "", err
	}
	req.Header.Set("Authorization", "Bearer "+cfg.token)
	req.Header.Set("Accept", "application/json")
	resp, err := client.Do(req)
	if err != nil {
		return false, "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return false, "", fmt.Errorf("estat %d", resp.StatusCode)
	}
	var out struct {
		Closed bool   `json:"closed"`
		Input  string `json:"input"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return false, "", err
	}
	return out.Closed, out.Input, nil
}

// pushConsole envia una part de la sortida del bash; retorna si la sessió ja s'ha tancat.
func pushConsole(ctx context.Context, client *http.Client, cfg config, id, output string) bool {
	resp, err := postJSON(ctx, client, cfg, "/api/agent/console/"+id+"/push", map[string]any{"output": output})
	if err != nil {
		return false
	}
	defer resp.Body.Close()
	var out struct {
		Closed bool `json:"closed"`
	}
	_ = json.NewDecoder(resp.Body).Decode(&out)
	return out.Closed
}

func postJSON(ctx context.Context, client *http.Client, cfg config, path string, body any) (*http.Response, error) {
	data, err := json.Marshal(body)
	if err != nil {
		return nil, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(cfg.url, "/")+path, bytes.NewReader(data))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+cfg.token)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	return client.Do(req)
}

// install deixa l'agent com a servei de systemd que arrenca sol. Cal ser root. Amb --user, el
// servei corre sota aquell usuari del sistema (hereta el seu entorn i credencials, p. ex. l'AWS
// CLI configurat a la seva WSL) en comptes de root.
func install(cfg config) error {
	self, err := os.Executable()
	if err != nil {
		return err
	}
	const dest = "/usr/local/bin/heimdeye-agent"
	if self != dest {
		if err := copyFile(self, dest, 0o755); err != nil {
			return fmt.Errorf("no s'ha pogut copiar el binari a %s: %w", dest, err)
		}
	}

	// Perquè l'agent trobi la configuració de l'usuari (p. ex. ~/.aws), es fixa User i HOME.
	var runAs string
	if target := resolveUser(cfg, os.Getenv("SUDO_USER")); target != "" {
		u, err := user.Lookup(target)
		if err != nil {
			return fmt.Errorf("l'usuari %q no existeix: %w", target, err)
		}
		runAs = fmt.Sprintf("User=%s\nEnvironment=HOME=%s\n", u.Username, u.HomeDir)
		fmt.Printf("el servei correrà com a %s\n", u.Username)
	}

	unit := serviceUnit(cfg, dest, runAs)

	path := "/etc/systemd/system/heimdeye-agent.service"
	if err := os.WriteFile(path, []byte(unit), 0o600); err != nil {
		return fmt.Errorf("no s'ha pogut escriure %s (cal ser root?): %w", path, err)
	}
	for _, args := range [][]string{{"daemon-reload"}, {"enable", "--now", "heimdeye-agent"}} {
		c := exec.Command("systemctl", args...)
		c.Stdout, c.Stderr = os.Stdout, os.Stderr
		if err := c.Run(); err != nil {
			return fmt.Errorf("systemctl %s: %w", strings.Join(args, " "), err)
		}
	}
	fmt.Println("servei heimdeye-agent instal·lat i engegat.")
	return nil
}

// resolveUser decideix sota quin usuari del sistema ha de córrer el servei ("" vol dir root, sense
// línia User=). Si s'ha donat --user, mana (amb "root" explícit = root). Si no, per defecte
// l'usuari que ha llançat el sudo (SUDO_USER), que és qui té configurat l'entorn (p. ex. l'AWS CLI
// d'una WSL), de manera que la comanda copiada no s'ha d'editar. Si no hi ha SUDO_USER, root.
func resolveUser(cfg config, sudoUser string) string {
	if cfg.user != "" {
		if cfg.user == "root" {
			return ""
		}

		return cfg.user
	}
	if sudoUser != "" && sudoUser != "root" {
		return sudoUser
	}

	return ""
}

// serviceUnit construeix el fitxer de servei de systemd. runAs és buit (corre com a root) o les
// línies "User=.../Environment=HOME=..." quan el servei ha de córrer sota un usuari concret.
func serviceUnit(cfg config, dest, runAs string) string {
	return fmt.Sprintf(`[Unit]
Description=HeimdEye agent (%s)
After=network-online.target
Wants=network-online.target

[Service]
%sExecStart=%s run --url %s --token %s --hostname %s
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
`, cfg.hostname, runAs, dest, cfg.url, cfg.token, cfg.hostname)
}

func copyFile(src, dst string, mode os.FileMode) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.OpenFile(dst, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, mode)
	if err != nil {
		return err
	}
	defer out.Close()
	if _, err := io.Copy(out, in); err != nil {
		return err
	}
	return out.Chmod(mode)
}

// baseEnv dona només PATH i HOME; la resta de variables venen de HeimdEye.
func baseEnv() []string {
	env := []string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"}
	if home := os.Getenv("HOME"); home != "" {
		env = append(env, "HOME="+home)
	}
	return env
}

func flatten(m map[string]string) []string {
	out := make([]string, 0, len(m))
	for k, v := range m {
		out = append(out, k+"="+v)
	}
	return out
}

func privateIP() string {
	addrs, err := net.InterfaceAddrs()
	if err != nil {
		return ""
	}
	for _, a := range addrs {
		if ipnet, ok := a.(*net.IPNet); ok && !ipnet.IP.IsLoopback() && ipnet.IP.To4() != nil {
			return ipnet.IP.String()
		}
	}
	return ""
}

// publicIP prova de detectar la IP pública; si no es pot, es deixa buida (no és imprescindible).
func publicIP(ctx context.Context, client *http.Client) string {
	c, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(c, http.MethodGet, "https://api.ipify.org", nil)
	if err != nil {
		return ""
	}
	resp, err := client.Do(req)
	if err != nil {
		return ""
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return ""
	}
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 64))
	return strings.TrimSpace(string(b))
}

func osName() string {
	data, err := os.ReadFile("/etc/os-release")
	if err != nil {
		return ""
	}
	for _, line := range strings.Split(string(data), "\n") {
		if strings.HasPrefix(line, "PRETTY_NAME=") {
			return strings.Trim(strings.TrimPrefix(line, "PRETTY_NAME="), `"`)
		}
	}
	return ""
}

func urlQueryEscape(s string) string {
	var b strings.Builder
	for _, r := range []byte(s) {
		if (r >= 'A' && r <= 'Z') || (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') || r == '-' || r == '_' || r == '.' || r == '~' {
			b.WriteByte(r)
		} else {
			fmt.Fprintf(&b, "%%%02X", r)
		}
	}
	return b.String()
}

func sleep(ctx context.Context, d time.Duration) {
	select {
	case <-ctx.Done():
	case <-time.After(d):
	}
}
