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
