// Comando heimdeye-agent: l'agent que l'alumne instal·la a la seva instància.
//
// Model pull: l'agent es registra a HeimdEye i, amb long polling, recull la feina pendent,
// l'executa en local (bash, com a root) i en retorna el codi de sortida i la sortida. No obre
// cap port: tota la comunicació surt de la instància cap a HeimdEye, xifrada per HTTPS.
//
// Un sol binari estàtic, sense dependències (només la biblioteca estàndard de Go). De moment
// Linux; es pot compilar per a altres sistemes sense canvis.
//
//	heimdeye-agent run     --url https://heimdeye... --token XXX --hostname web
//	heimdeye-agent install --url https://heimdeye... --token XXX --hostname web
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
	"strings"
	"syscall"
	"time"
)

const version = "1.1.0"

type config struct {
	url      string
	token    string
	hostname string
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
  heimdeye-agent install --url URL --token TOKEN --hostname NOM
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
// L'agent no l'interpreta; el torna tal qual al resultat (json.RawMessage).
type task struct {
	ID      json.RawMessage   `json:"id"`
	Code    string            `json:"code"`
	Env     map[string]string `json:"env"`
	Timeout int               `json:"timeout"`
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

// install deixa l'agent com a servei de systemd que arrenca sol. Cal ser root.
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
	unit := fmt.Sprintf(`[Unit]
Description=HeimdEye agent (%s)
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=%s run --url %s --token %s --hostname %s
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
`, cfg.hostname, dest, cfg.url, cfg.token, cfg.hostname)

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
