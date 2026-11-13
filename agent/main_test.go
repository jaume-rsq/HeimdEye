package main

import (
	"strings"
	"testing"
)

func TestServiceUnitDefaultsToRoot(t *testing.T) {
	cfg := config{url: "https://heimdeye.test", token: "TOK", hostname: "web"}
	unit := serviceUnit(cfg, "/usr/local/bin/heimdeye-agent", "")

	if strings.Contains(unit, "User=") {
		t.Errorf("sense --user el servei no ha de fixar User=, però el unit és:\n%s", unit)
	}
	if !strings.Contains(unit, "ExecStart=/usr/local/bin/heimdeye-agent run --url https://heimdeye.test --token TOK --hostname web") {
		t.Errorf("ExecStart inesperat:\n%s", unit)
	}
}

func TestServiceUnitRunsAsUserWhenRequested(t *testing.T) {
	cfg := config{url: "https://heimdeye.test", token: "TOK", hostname: "aws", user: "alumne"}
	runAs := "User=alumne\nEnvironment=HOME=/home/alumne\n"
	unit := serviceUnit(cfg, "/usr/local/bin/heimdeye-agent", runAs)

	for _, want := range []string{"User=alumne", "Environment=HOME=/home/alumne"} {
		if !strings.Contains(unit, want) {
			t.Errorf("falta %q al unit:\n%s", want, unit)
		}
	}
	// Les línies d'usuari han d'anar dins [Service], abans de l'ExecStart.
	if strings.Index(unit, "User=alumne") > strings.Index(unit, "ExecStart=") {
		t.Errorf("User= ha d'anar abans d'ExecStart=:\n%s", unit)
	}
}

func TestResolveUser(t *testing.T) {
	cases := []struct {
		name     string
		flagUser string
		sudoUser string
		want     string
	}{
		{"default to sudo user", "", "jaume", "jaume"},
		{"no sudo user -> root", "", "", ""},
		{"sudo user root -> root", "", "root", ""},
		{"explicit flag wins over sudo", "altre", "jaume", "altre"},
		{"explicit root forces root", "root", "jaume", ""},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := resolveUser(config{user: c.flagUser}, c.sudoUser); got != c.want {
				t.Errorf("resolveUser(user=%q, sudo=%q) = %q, vol %q", c.flagUser, c.sudoUser, got, c.want)
			}
		})
	}
}

func TestTailKeepsTheLastNonEmptyLines(t *testing.T) {
	got := tail("a\n\nb\nc\n\n", 2)
	if len(got) != 2 || got[0] != "b" || got[1] != "c" {
		t.Fatalf("tail = %q", got)
	}
	if len(tail("", 3)) != 0 {
		t.Fatal("tail d'una sortida buida no és buit")
	}
}

func TestBasePathFindsSnapAndUserTools(t *testing.T) {
	env := basePath("/home/alumne")
	if len(env) != 2 || env[1] != "HOME=/home/alumne" {
		t.Fatalf("env inesperat: %v", env)
	}
	for _, dir := range []string{"/home/alumne/.local/bin", "/home/alumne/bin", "/usr/bin", "/snap/bin"} {
		if !strings.Contains(env[0], dir) {
			t.Errorf("al PATH hi falta %s: %s", dir, env[0])
		}
	}
	if env := basePath(""); len(env) != 1 || strings.Contains(env[0], ".local") {
		t.Errorf("sense HOME no hi ha d'haver dirs d'usuari: %v", env)
	}
}
