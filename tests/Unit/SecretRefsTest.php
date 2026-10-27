<?php

namespace Tests\Unit;

use App\Enums\SecretType;
use App\Models\Secret;
use App\Runner\SecretRefs;
use PHPUnit\Framework\TestCase;

class SecretRefsTest extends TestCase
{
    private function secret(string $key, SecretType $type): Secret
    {
        return new Secret(['clau' => $key, 'tipus' => $type]);
    }

    public function test_bash_mentions_are_the_environment_variables_of_each_field(): void
    {
        $jaume = $this->secret('jaume', SecretType::Login);

        $this->assertSame(['HE_SECRET_JAUME_PASSWORD'], SecretRefs::envNamesIn('echo $HE_SECRET_JAUME_PASSWORD', $jaume));
        $this->assertSame(['HE_SECRET_JAUME_USER'], SecretRefs::envNamesIn('ssh "$HE_SECRET_JAUME_USER@x"', $jaume));
        // El nom sol és un text (un usuari), no el secret.
        $this->assertFalse(SecretRefs::inBash('id jaume', $jaume));
        $this->assertFalse(SecretRefs::inBash('echo $HE_SECRET_JAUME', $jaume));

        $token = $this->secret('token', SecretType::Value);
        $this->assertTrue(SecretRefs::inBash('curl -H "X: $HE_SECRET_TOKEN"', $token));
        $this->assertFalse(SecretRefs::inBash('echo $HE_SECRET_TOKEN_2', $token));
    }

    public function test_only_the_user_and_public_key_are_not_sensitive_in_bash(): void
    {
        $key = $this->secret('ssh_jaume', SecretType::SshKey);

        $this->assertSame([], SecretRefs::sensitiveEnvNamesIn('echo "$HE_SECRET_SSH_JAUME_PUBKEY" >> ~$HE_SECRET_SSH_JAUME_USER/.ssh/authorized_keys', $key));
        $this->assertSame(['HE_SECRET_SSH_JAUME_KEY_FILE'], SecretRefs::sensitiveEnvNamesIn('cat $HE_SECRET_SSH_JAUME_KEY_FILE', $key));
    }

    public function test_ansible_mentions_are_inside_expressions(): void
    {
        $jaume = $this->secret('jaume', SecretType::Login);

        $this->assertTrue(SecretRefs::inAnsible('password: "{{ jaume.password | password_hash }}"', $jaume));
        $this->assertTrue(SecretRefs::inAnsible("  when: jaume.user == 'x'", $jaume));
        $this->assertFalse(SecretRefs::inAnsible('ansible.builtin.user: name=jaume', $jaume));
        $this->assertFalse(SecretRefs::inAnsible('msg: "{{ item.jaume }}"', $jaume));
    }

    public function test_user_and_public_key_do_not_leak_in_ansible(): void
    {
        $this->assertFalse(SecretRefs::sensitiveIn(' jaume.user ', 'jaume'));
        $this->assertFalse(SecretRefs::sensitiveIn(" ssh_jaume['pubkey'] ", 'ssh_jaume'));
        $this->assertTrue(SecretRefs::sensitiveIn(' jaume.password ', 'jaume'));
        $this->assertTrue(SecretRefs::sensitiveIn(' jaume ', 'jaume'));
        $this->assertTrue(SecretRefs::sensitiveIn(" lookup('env', 'HE_SECRET_JAUME_PASSWORD') ", 'jaume'));
        $this->assertFalse(SecretRefs::sensitiveIn(" lookup('env', 'HE_SECRET_JAUME_USER') ", 'jaume'));
    }

    public function test_values_given_to_the_code(): void
    {
        $jaume = new Secret(['clau' => 'jaume', 'tipus' => SecretType::Login, 'nom_usuari' => 'jaume']);
        $jaume->setRawAttributes([...$jaume->getAttributes(), 'valor' => null]);

        $this->assertSame(['user' => 'jaume', 'password' => ''], SecretRefs::ansibleValue($jaume));
        $this->assertSame(['HE_SECRET_JAUME_USER', 'HE_SECRET_JAUME_PASSWORD'], array_keys(SecretRefs::env($jaume)));
    }
}
