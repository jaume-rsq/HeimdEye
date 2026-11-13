<?php

namespace Tests\Unit;

use App\Enums\CheckStatus;
use App\Runner\CheckResult;
use App\Runner\Masker;
use PHPUnit\Framework\TestCase;

class MaskerTest extends TestCase
{
    public function test_whole_secrets_are_masked(): void
    {
        $this->assertSame('pass=******** ok', Masker::mask('pass=S3cretValue ok', ['S3cretValue']));
        $this->assertNull(Masker::mask(null, ['S3cretValue']));
    }

    public function test_a_secret_cut_by_the_output_limit_is_masked_too(): void
    {
        // El retall de la sortida ha partit el secret: només en queda el principi.
        $this->assertSame('pass=********', Masker::maskTruncated('pass=S3cretV', ['S3cretValue']));
        $this->assertSame('********', Masker::maskTruncated('S3c', ['S3cretValue']));
    }

    public function test_text_without_a_cut_secret_is_left_alone(): void
    {
        $this->assertSame('tot correcte', Masker::maskTruncated('tot correcte', ['S3cretValue']));
        // Menys de 3 lletres del principi no es consideren un secret partit.
        $this->assertSame('final S3', Masker::maskTruncated('final S3', ['S3cretValue']));
        // El secret sencer el tapa mask(); el final no es torna a tocar.
        $this->assertSame('a ******** b', Masker::maskTruncated('a S3cretValue b', ['S3cretValue']));
    }

    public function test_check_results_mask_the_message_and_the_output(): void
    {
        $result = (new CheckResult(CheckStatus::Fail, 'clau S3cretVal', 'sortida S3cretValue i S3cret'))->masked(['S3cretValue']);

        $this->assertSame('clau ********', $result->message);
        $this->assertSame('sortida ******** i ********', $result->output);
    }
}
