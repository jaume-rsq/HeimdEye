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
