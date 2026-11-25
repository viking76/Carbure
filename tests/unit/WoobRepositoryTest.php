<?php

use Carbure\Lib\Config;
use Carbure\Lib\Woob;
use Carbure\Lib\WoobRepository;
use PHPUnit\Framework\TestCase;

class WoobRepositoryTest extends TestCase
{
    /** Fingerprint of the keyring of the fake repository (tests/fixtures/woob-repository) */
    private string $fingerprint;

    /** SHA-256 of the keyring of the fake repository, the one Carbure pins */
    private string $sha256;

    protected function setUp(): void
    {
        $this->fingerprint = sha1_file(dirname(__DIR__) . '/fixtures/woob-repository/trusted.gpg');
        $this->sha256 = hash_file('sha256', dirname(__DIR__) . '/fixtures/woob-repository/trusted.gpg');
        $this->forget();
    }

    protected function tearDown(): void
    {
        WoobRepository::$officialRepository = WoobRepository::DEFAULT_REPOSITORY;
        WoobRepository::$officialKeyrings = WoobRepository::OFFICIAL_KEYRINGS;
        $this->forget();
        @unlink(carbure_test_tmp('carbure-fake-woob-other-keyring'));
    }

    /** The fake woob trusts no keyring */
    private function forget(): void
    {
        array_map('unlink', glob(WoobRepository::$keyringDir . '/*') ?: []);
        @rmdir((string)WoobRepository::$keyringDir);
    }

    /**
     * Update woob, returning the result and the lines written by woob.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function update(?string $fingerprint): array
    {
        $lines = [];
        $result = WoobRepository::update($fingerprint, function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        return [$result, $lines];
    }

    public function testMatchesOnlyTheConfirmedFingerprint()
    {
        $keyring = str_repeat('ab', 20);
        $this->assertTrue(WoobRepository::matches($keyring, $keyring));
        $this->assertTrue(WoobRepository::matches($keyring, ' ' . strtoupper($keyring)));
        $this->assertFalse(WoobRepository::matches($keyring, str_repeat('cd', 20)));
        $this->assertFalse(WoobRepository::matches($keyring, null));
        $this->assertFalse(WoobRepository::matches($keyring, ''));
    }

    public function testStatusOfARepositoryNotTrusted()
    {
        $status = WoobRepository::status();
        $this->assertFalse($status['trusted']);
        // The fingerprint to confirm
        $this->assertSame($this->fingerprint, $status['fingerprint']);
        $this->assertNull($status['error']);
    }

    public function testRepositoryNotReachable()
    {
        $repository = Config::get('woob_repository');
        Config::set('woob_repository', carbure_test_tmp('carbure-no-repository'));
        try {
            $status = WoobRepository::status();
            [$result, $lines] = $this->update($this->fingerprint);
        } finally {
            Config::set('woob_repository', $repository);
        }
        $this->assertFalse($status['trusted']);
        $this->assertNull($status['fingerprint']);
        $this->assertStringContainsString('not downloaded', $status['error']);
        // Nothing to compare with: woob is not started
        $this->assertFalse($result['updated']);
        $this->assertStringContainsString('not downloaded', $result['message']);
        $this->assertSame([], $lines);
        $this->assertFalse(WoobRepository::isTrusted());
    }

    public function testWoobNotStartedWithoutTheConfirmedFingerprint()
    {
        // woob would trust any keyring by itself (no terminal): it is not even started
        foreach ([null, str_repeat('0', 40)] as $confirmed) {
            [$result, $lines] = $this->update($confirmed);
            $this->assertFalse($result['updated']);
            $this->assertTrue($result['refused']);
            $this->assertSame($this->fingerprint, $result['asked']);
            $this->assertSame([], $lines);
            $this->assertFalse(WoobRepository::isTrusted());
        }
    }

    public function testTheConfirmedKeyringIsTrustedThenNoMoreConfirmationNeeded()
    {
        [$result, $lines] = $this->update(strtoupper($this->fingerprint));
        $this->assertTrue($result['updated']);
        $this->assertFalse($result['refused']);
        $this->assertContains('Are you sure you want to continue? (Y/n): *** ASSUMING YES ***', $lines);
        $this->assertSame('100% All modules are up-to-date.', $result['message']);
        $this->assertSame(['repository' => WoobRepository::repository(), 'trusted' => true, 'fingerprint' => $this->fingerprint, 'error' => null],
            WoobRepository::status());

        // Trusted: woob checks the next keyrings itself
        [$result] = $this->update(null);
        $this->assertTrue($result['updated']);
        $this->assertNull($result['asked']);
    }

    public function testNoFirstRunOfWoobBeforeTheRepositoryIsTrusted()
    {
        // A fresh woob (no list of repositories): any command would refresh the repositories
        // first and trust their keyring by itself
        $sources = WoobRepository::$sourcesList;
        @unlink($sources);
        try {
            foreach ([fn() => Woob::listBackends(), fn() => Woob::listAccounts('bnp'), fn() => Woob::moduleFields('bnp'),
                fn() => Woob::getBankData('history', '123@bnp')] as $i => $call) {
                try {
                    $call();
                    $this->fail("Case $i: woob run before its repository is trusted");
                } catch (Error $e) {
                    $this->assertSame(409, $e->getCode(), "Case $i");
                    $this->assertStringContainsString('Trust the woob repository first', $e->getMessage());
                }
            }
            // woob not even started
            $this->assertFileDoesNotExist($sources);
            $this->assertFalse(WoobRepository::isTrusted());

            // The update confirmed by the administrator is the first run
            [$result] = $this->update($this->fingerprint);
            $this->assertTrue($result['updated']);
            $this->assertTrue(WoobRepository::isTrusted());
            $this->assertNotEmpty(Woob::listBackends());
        } finally {
            file_put_contents($sources, "https://updates.woob.tech/%(version)s/main/\n");
        }
    }

    public function testNoAutoUpdateBeforeTheRepositoryIsTrusted()
    {
        // --auto-update refreshes the repositories after a crash of a module
        $command = new ReflectionMethod(Woob::class, 'buildCommand');
        Config::set('woob_auto_update', true);
        $this->assertStringNotContainsString('--auto-update', $command->invoke(null, 'history', '1@bnp'));
        $this->update($this->fingerprint);
        $this->assertStringContainsString('--auto-update', $command->invoke(null, 'history', '1@bnp'));
    }

    public function testAKeyringStoredByWoobOtherThanTheConfirmedOneIsRemoved()
    {
        // The repository served another keyring to woob than to Carbure
        touch(carbure_test_tmp('carbure-fake-woob-other-keyring'));
        [$result] = $this->update($this->fingerprint);
        $this->assertFalse($result['updated']);
        $this->assertStringContainsString('is not the confirmed one', $result['message']);
        $this->assertFalse(WoobRepository::isTrusted());
        $this->assertSame([], glob(WoobRepository::$keyringDir . '/*') ?: []);
    }

    /**
     * Trust the official keyring pinned by Carbure, returning the result and the lines of woob.
     *
     * @return array{0: array<string, mixed>|null, 1: list<string>}
     */
    private function trustOfficial(): array
    {
        $lines = [];
        $result = WoobRepository::trustOfficial(function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        return [$result, $lines];
    }

    public function testThePinnedKeyringOfTheOfficialRepositoryIsTrustedWithoutAsking()
    {
        // The fake repository plays the official one, its keyring pinned
        WoobRepository::$officialRepository = WoobRepository::repository();
        WoobRepository::$officialKeyrings = [strtoupper($this->sha256)];

        [$result, $lines] = $this->trustOfficial();
        $this->assertTrue($result['updated']);
        $this->assertContains('Are you sure you want to continue? (Y/n): *** ASSUMING YES ***', $lines);
        $this->assertTrue(WoobRepository::isTrusted());
        $this->assertSame($this->fingerprint, WoobRepository::status()['fingerprint']);

        // Already trusted: nothing to do, woob not started
        [$result, $lines] = $this->trustOfficial();
        $this->assertNull($result);
        $this->assertSame([], $lines);
    }

    public function testAnotherKeyringOfTheOfficialRepositoryWaitsForAnAdministrator()
    {
        WoobRepository::$officialRepository = WoobRepository::repository();
        WoobRepository::$officialKeyrings = [str_repeat('0', 64)];

        [$result, $lines] = $this->trustOfficial();
        $this->assertFalse($result['updated']);
        $this->assertTrue($result['refused']);
        // The fingerprint the administrator is asked to confirm
        $this->assertSame($this->fingerprint, $result['asked']);
        $this->assertSame([], $lines);
        $this->assertFalse(WoobRepository::isTrusted());
    }

    public function testAnotherRepositoryIsNeverTrustedWithoutAsking()
    {
        // The fake repository is not the official one, even with the same keyring
        WoobRepository::$officialKeyrings = [$this->sha256];

        [$result, $lines] = $this->trustOfficial();
        $this->assertNull($result);
        $this->assertSame([], $lines);
        $this->assertFalse(WoobRepository::isTrusted());
    }

    public function testTheOfficialRepositoryNotReachable()
    {
        $repository = Config::get('woob_repository');
        Config::set('woob_repository', carbure_test_tmp('carbure-no-repository'));
        WoobRepository::$officialRepository = WoobRepository::repository();
        try {
            [$result, $lines] = $this->trustOfficial();
        } finally {
            Config::set('woob_repository', $repository);
        }
        $this->assertFalse($result['updated']);
        $this->assertFalse($result['refused']);
        $this->assertStringContainsString('not downloaded', $result['message']);
        $this->assertSame([], $lines);
    }

    public function testOnlyThePinnedFingerprintsAreOfficial()
    {
        $this->assertSame(['a1755b379793ff4922a7bfb5f566c019205f805b96f3420aee95938cd0d1634a'], WoobRepository::OFFICIAL_KEYRINGS);
        $this->assertSame('https://updates.woob.tech/3/main/', WoobRepository::$officialRepository);
        $this->assertTrue(WoobRepository::isOfficial('A1755B379793FF4922A7BFB5F566C019205F805B96F3420AEE95938CD0D1634A'));
        // The SHA-1 of the official keyring is not a pinned fingerprint
        $this->assertFalse(WoobRepository::isOfficial('b5fc136c2d500b8483696a0ee722865842a4cc45'));
        $this->assertFalse(WoobRepository::isOfficial(str_repeat('0', 64)));
        $this->assertFalse(WoobRepository::isOfficial(''));
    }
}
