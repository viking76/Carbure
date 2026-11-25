<?php

/**
 * WoobRepository.php
 *
 * Repository of the woob bank modules (Python code that reads the bank credentials):
 * the trust of its keyring and the update of the modules, decided by an administrator.
 *
 * The first time woob refreshes its repositories, it trusts the keyring of the
 * repository; without a terminal it does not even ask ("*** ASSUMING YES ***"). woob
 * then checks the signature of every module with it, and each next keyring with the
 * previous one. So, while no keyring is trusted, Carbure starts woob only once an
 * administrator confirmed the fingerprint (SHA-1) of the keyring that Carbure downloads
 * itself, and checks afterwards that woob stored that very keyring. The keyring of the
 * official repository is pinned (OFFICIAL_KEYRINGS, SHA-256): trusted at the first start
 * without asking (trustOfficial()); any other one still waits for an administrator. The
 * fingerprint shown to the administrator stays the SHA-1 woob prints itself, so that it
 * can be compared; the automatic trust relies on SHA-256 only, collision-resistant.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Error;
use Exception;

final class WoobRepository {

    /** Repository of the bank modules of woob 3 */
    public const DEFAULT_REPOSITORY = 'https://updates.woob.tech/3/main/';

    /**
     * Fingerprints (SHA-256 of trusted.gpg) of the keyring of the official repository
     * (DEFAULT_REPOSITORY), checked by the maintainers of Carbure: trusted at the first
     * start without asking an administrator (#471). Keys of the maintainers of woob
     * (Romain Bignon, Florent Fourcot), unchanged since February 2023 (SHA-1
     * b5fc136c2d500b8483696a0ee722865842a4cc45). SHA-1 is not used here: it is broken
     * against chosen-prefix collisions, and a keyring may carry filler packets.
     */
    public const OFFICIAL_KEYRINGS = ['a1755b379793ff4922a7bfb5f566c019205f805b96f3420aee95938cd0d1634a'];

    /** Repository whose keyring is pinned (OFFICIAL_KEYRINGS), replaced by the tests */
    public static string $officialRepository = self::DEFAULT_REPOSITORY;

    /**
     * Pinned fingerprints of the official repository, replaced by the tests
     *
     * @var list<string>
     */
    public static array $officialKeyrings = self::OFFICIAL_KEYRINGS;

    /** woob trusting a keyring by itself (no terminal to ask): logged */
    private const ASSUMING_YES = '*** ASSUMING YES ***';

    /** An update that went wrong, in the output of woob */
    private const FAILURE = '/aborted|error|not trusted|invalid signature|unavailable/i';

    /** Seconds of silence from woob before sending an SSE heartbeat */
    private const HEARTBEAT_INTERVAL = 15;

    /**
     * Directory of the keyrings trusted by woob, replaced by the tests (null: the one of
     * the woob home, ~/.local/share/woob/keyrings)
     */
    public static ?string $keyringDir = null;

    /**
     * List of the repositories of woob, replaced by the tests (null: the one of the woob
     * home, ~/.config/woob/sources.list). Missing, any woob command creates it and
     * refreshes the repositories first, trusting their keyring by itself.
     */
    public static ?string $sourcesList = null;

    /**
     * Repository of the bank modules (woob_repository, else the one of woob 3).
     *
     * @return string The URL (or directory, in the tests), ending with a slash
     */
    public static function repository(): string
    {
        $repository = Config::has('woob_repository') ? (string)Config::get('woob_repository') : self::DEFAULT_REPOSITORY;
        return rtrim($repository, '/') . '/';
    }

    /**
     * Whether woob already trusts the keyring of its repository.
     *
     * @return bool
     */
    public static function isTrusted(): bool
    {
        return self::trustedKeyring() !== null;
    }

    /**
     * Refuse a woob command that would make woob trust the keyring of its repository by
     * itself: any command, while woob has no list of repositories yet (a first run creates
     * it and refreshes the repositories) and no keyring is trusted.
     *
     * @return void
     * @throws Error If the woob repository must be trusted first (409)
     */
    public static function requireTrustBeforeFirstRun(): void
    {
        $sources = self::$sourcesList ?? rtrim((string)Woob::home(), '/') . '/.config/woob/sources.list';
        if (!is_file($sources) && !self::isTrusted()) {
            throw new Error(self::TRUST_FIRST, 409);
        }
    }

    /** Message of a woob action refused before the repository is trusted */
    public const TRUST_FIRST = "Trust the woob repository first, from the Accounts page (update the bank modules)";

    /**
     * State of the repository: trusted or not, and the fingerprint of its keyring (the
     * one trusted, else the one to confirm before trusting it).
     *
     * @return array{repository: string, trusted: bool, fingerprint: string|null, error: string|null}
     */
    public static function status(): array
    {
        $trusted = self::trustedKeyring();
        if ($trusted !== null) {
            return ['repository' => self::repository(), 'trusted' => true, 'fingerprint' => sha1_file($trusted) ?: null, 'error' => null];
        }
        [$fingerprint, $error] = self::remoteFingerprint();
        return ['repository' => self::repository(), 'trusted' => false, 'fingerprint' => $fingerprint, 'error' => $error];
    }

    /**
     * Update the repositories and the bank modules of woob ("woob config update"). While
     * no keyring is trusted, woob is started only if the fingerprint confirmed by the
     * administrator is the one of the keyring Carbure downloads (woob would trust any
     * keyring by itself), and the keyring woob stored must be that one.
     *
     * @param string|null $fingerprint Fingerprint confirmed by the administrator (null: none)
     * @param callable    $onMessage   Receives each line written by woob
     * @param string|null $pinned      SHA-256 the keyring must also have (official keyring
     *                                 trusted without asking), null for a manual confirmation
     * @return array{updated: bool, asked: string|null, refused: bool, message: string} updated:
     *         success; asked: fingerprint to confirm (null if none); refused: woob not started,
     *         the keyring is not confirmed; message: cause of a failure, else last line of woob
     */
    public static function update(?string $fingerprint, callable $onMessage, ?string $pinned = null): array
    {
        $confirmed = $fingerprint === null ? null : strtolower(trim($fingerprint));
        $trusted = self::isTrusted();
        $check = $trusted ? null : self::checkConfirmed($confirmed, $pinned);
        if ($check !== null) {
            return $check;
        }
        [$code, $output] = self::run($onMessage);
        if (!$trusted && $confirmed !== null) {
            $stored = self::checkStoredKeyring($confirmed, $pinned);
            if ($stored !== null) {
                return $stored;
            }
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $output) ?: [])));
        $updated = $code === 0 && !preg_match(self::FAILURE, $output);
        return ['updated' => $updated, 'asked' => null, 'refused' => false, 'message' => (string)end($lines)];
    }

    /**
     * Trust the keyring of the official repository without asking an administrator, when
     * it is exactly the one pinned by Carbure (OFFICIAL_KEYRINGS), then update the bank
     * modules: run at each start of the Docker container, so that the banks can be
     * connected at once. Any other keyring, or another repository, still waits for the
     * confirmation of an administrator (Accounts page).
     *
     * @param callable $onMessage Receives each line written by woob
     * @return array{updated: bool, asked: string|null, refused: bool, message: string}|null
     *         The result of the update (see update()), null when there is nothing to do:
     *         keyring already trusted, or a repository other than the official one
     * @throws Exception If woob cannot be started
     */
    public static function trustOfficial(callable $onMessage): ?array
    {
        if (self::isTrusted() || self::repository() !== self::$officialRepository) {
            return null;
        }
        [$keyring, $error] = self::remoteKeyring();
        if ($keyring === null) {
            return ['updated' => false, 'asked' => null, 'refused' => false, 'message' => (string)$error];
        }
        $remote = sha1($keyring);
        $pinned = hash('sha256', $keyring);
        if (!self::isOfficial($pinned)) {
            Logger::warn("Keyring $remote of " . self::repository() . " is not the one pinned by Carbure: an administrator must confirm it");
            return ['updated' => false, 'asked' => $remote, 'refused' => true, 'message' => "Keyring $remote is not the official one"];
        }
        Logger::info("Keyring $remote of " . self::repository() . " is the official one pinned by Carbure: trusted");
        return self::update($remote, $onMessage, $pinned);
    }

    /**
     * Whether a fingerprint is one of the official keyring pinned by Carbure.
     *
     * @param string $fingerprint SHA-256 of a keyring
     * @return bool
     */
    public static function isOfficial(string $fingerprint): bool
    {
        foreach (self::$officialKeyrings as $official) {
            if (self::matches($official, $fingerprint)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a fingerprint confirmed by the administrator is the one of the keyring of
     * the repository (case ignored).
     *
     * @param string      $keyring   Fingerprint of the keyring
     * @param string|null $confirmed Fingerprint confirmed by the administrator
     * @return bool
     */
    public static function matches(string $keyring, ?string $confirmed): bool
    {
        return $confirmed !== null && $confirmed !== '' && hash_equals(strtolower($keyring), strtolower(trim($confirmed)));
    }

    /**
     * Before trusting the keyring of the repository: the confirmed fingerprint must be
     * the one of the keyring downloaded now (and its SHA-256 the pinned one, if any).
     *
     * @param string|null $confirmed Fingerprint confirmed by the administrator
     * @param string|null $pinned    SHA-256 the keyring must have, null if none
     * @return array{updated: bool, asked: string|null, refused: bool, message: string}|null
     *         Why woob must not be started, null if it can
     */
    private static function checkConfirmed(?string $confirmed, ?string $pinned): ?array
    {
        [$keyring, $error] = self::remoteKeyring();
        if ($keyring === null) {
            return ['updated' => false, 'asked' => null, 'refused' => false, 'message' => (string)$error];
        }
        $remote = sha1($keyring);
        if (!self::matches($remote, $confirmed) || ($pinned !== null && !self::matches(hash('sha256', $keyring), $pinned))) {
            Logger::warn("Keyring $remote of " . self::repository() . " not confirmed: woob not started");
            return ['updated' => false, 'asked' => $remote, 'refused' => true, 'message' => "Keyring $remote not confirmed"];
        }
        Logger::info("Keyring $remote of " . self::repository() . " confirmed by an administrator");
        return null;
    }

    /**
     * After the first update: the keyring woob stored must be the confirmed one (woob
     * downloaded it again), with the pinned SHA-256 if any, else it is removed.
     *
     * @param string      $confirmed Fingerprint confirmed by the administrator
     * @param string|null $pinned    SHA-256 the keyring must have, null if none
     * @return array{updated: bool, asked: string|null, refused: bool, message: string}|null
     *         The failure, null if the keyring stored is the confirmed one
     */
    private static function checkStoredKeyring(string $confirmed, ?string $pinned): ?array
    {
        $stored = self::trustedKeyring();
        $fingerprint = $stored !== null ? (string)sha1_file($stored) : null;
        $pinnedOk = $pinned === null || ($stored !== null && self::matches((string)hash_file('sha256', $stored), $pinned));
        if ($fingerprint !== null && self::matches($fingerprint, $confirmed) && $pinnedOk) {
            return null;
        }
        foreach (array_merge(glob(self::keyringDir() . '/*.gpg') ?: [], glob(self::keyringDir() . '/*.version') ?: []) as $file) {
            @unlink($file);
        }
        $message = "keyring " . ($fingerprint ?? 'none') . " is not the confirmed one ($confirmed)";
        Logger::error("woob stored a keyring that is not the confirmed one: $message, removed");
        return ['updated' => false, 'asked' => null, 'refused' => false, 'message' => $message];
    }

    /**
     * Run "woob config update" without any input, giving each line it writes to the callback.
     *
     * @param callable $onMessage Receives each line
     * @return array{0: int, 1: string} Exit code and output of woob
     * @throws Exception If woob cannot be started
     */
    private static function run(callable $onMessage): array
    {
        $process = proc_open(Woob::path(true) . ' config update', [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new Exception("Failed to start woob");
        }
        $state = ['output' => '', 'pending' => [1 => '', 2 => '']];
        self::read([1 => $pipes[1], 2 => $pipes[2]], $state, $onMessage);
        $code = proc_close($process);
        if (str_contains($state['output'], self::ASSUMING_YES)) {
            Logger::info("woob trusted the keyring of " . self::repository() . " by itself (ASSUMING YES)");
        }
        return [$code, $state['output']];
    }

    /**
     * Read the outputs of woob until it ends, giving each line to the callback.
     *
     * @param array<int, resource>  $open      Pipes to read: 1 (stdout), 2 (stderr)
     * @param array<string, mixed>  $state     Output and pending lines (updated)
     * @param callable              $onMessage Receives each line
     * @return void
     */
    private static function read(array $open, array &$state, callable $onMessage): void
    {
        while (!empty($open)) {
            $read = array_values($open);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, self::HEARTBEAT_INTERVAL) === 0) {
                Webservice::sendHeartbeat();
                continue;
            }
            foreach ($open as $index => $pipe) {
                if (in_array($pipe, $read, true)) {
                    self::readChunk($index, $open, $state, $onMessage);
                }
            }
        }
    }

    /**
     * Read what a pipe has, give its complete lines to the callback, close it at its end.
     *
     * @param int                   $index     Index of the pipe
     * @param array<int, resource>  $open      Pipes still open (updated)
     * @param array<string, mixed>  $state     Output and pending lines (updated)
     * @param callable              $onMessage Receives each line
     * @return void
     */
    private static function readChunk(int $index, array &$open, array &$state, callable $onMessage): void
    {
        $chunk = fread($open[$index], 8192);
        if ($chunk === false || ($chunk === '' && feof($open[$index]))) {
            fclose($open[$index]);
            unset($open[$index]);
            $chunk = "\n";
        }
        $state['output'] .= $chunk;
        $state['pending'][$index] .= $chunk;
        // A progress bar rewrites its line with \r: each version is a line
        $parts = preg_split('/[\r\n]/', $state['pending'][$index]) ?: [];
        $state['pending'][$index] = (string)array_pop($parts);
        foreach ($parts as $line) {
            $line = trim((string)preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line));
            if ($line !== '') {
                $onMessage(mb_substr($line, 0, 300));
            }
        }
    }

    /**
     * Directory of the keyrings trusted by woob.
     *
     * @return string
     */
    private static function keyringDir(): string
    {
        return self::$keyringDir ?? rtrim((string)Woob::home(), '/') . '/.local/share/woob/keyrings';
    }

    /**
     * Keyring of the repository already trusted by woob.
     *
     * @return string|null Its file, null if the repository is not trusted yet
     */
    private static function trustedKeyring(): ?string
    {
        $dir = self::keyringDir();
        // Named after the URL of the repository by woob ("https___updates_woob_tech_3_main_.gpg")
        $file = $dir . '/' . preg_replace('/[^A-Za-z0-9]/', '_', self::repository()) . '.gpg';
        if (is_file($file)) {
            return $file;
        }
        $any = glob($dir . '/*.gpg') ?: [];
        return $any[0] ?? null;
    }

    /**
     * Fingerprint of the keyring of the repository (SHA-1 of trusted.gpg, the one woob
     * prints), downloaded now.
     *
     * @return array{0: string|null, 1: string|null} The fingerprint, or null and the error
     */
    private static function remoteFingerprint(): array
    {
        [$keyring, $error] = self::remoteKeyring();
        return [$keyring === null ? null : sha1($keyring), $error];
    }

    /**
     * Keyring of the repository (trusted.gpg), downloaded now.
     *
     * @return array{0: string|null, 1: string|null} The keyring, or null and the error
     */
    private static function remoteKeyring(): array
    {
        $url = self::repository() . 'trusted.gpg';
        $context = stream_context_create(['http' => ['timeout' => 10, 'follow_location' => 0]]);
        $keyring = @file_get_contents($url, false, $context);
        if ($keyring === false || $keyring === '') {
            Logger::warn("Keyring of the woob repository not downloaded: $url");
            return [null, "Keyring of the woob repository not downloaded ($url)"];
        }
        return [$keyring, null];
    }
}
