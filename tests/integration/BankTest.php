<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\Logger;
use Carbure\Lib\Messages;
use Carbure\Lib\Webservice;
use Carbure\Lib\Woob;
use Carbure\Lib\WoobRepository;
use Carbure\Resources\Bank;
use Carbure\Resources\Transaction;

class BankTest extends DatabaseTestCase
{
    /**
     * Run the synchronization and return the SSE output.
     */
    private function sync($token = null)
    {
        ob_start();
        try {
            Bank::getSync($token);
        } finally {
            $output = ob_get_clean();
        }
        return $output;
    }

    public function testEveryUserSeesTheAccountsOfTheHousehold()
    {
        $this->loginAs(self::USER);
        $accounts = Bank::get();
        // 111@bnp is followed twice (by two users): listed once
        $this->assertSame(['fail@bank', '111@bnp'], array_column($accounts, 'bankId'));
        $this->assertSame(['bankId' => '111@bnp', 'account_number' => '111', 'bank_name' => 'bnp', 'label' => null, 'manual' => false],
            array_diff_key($accounts[1], ['id' => true]));
        $this->assertGreaterThan(0, $accounts[1]['id']);

        $this->loginAs(self::ADMIN);
        $this->assertSame($accounts, Bank::get());
    }

    public function testCrud()
    {
        $this->loginAs(self::ADMIN);
        $before = count(Bank::accounts());

        // user_id: the administrator who added the account
        $account = Bank::create('222', 'bnp');
        $this->assertSame('222@bnp', $account['bankId']);
        $this->assertSame(self::ADMIN, $account['user_id']);
        $this->assertCount($before + 1, Bank::accounts());

        $updated = Bank::update($account['id'], '333', 'creditmutuel');
        $this->assertSame('333@creditmutuel', $updated['bankId']);
        $this->assertSame(self::ADMIN, $updated['user_id']);
        $this->assertContains('333@creditmutuel', array_column(Bank::accounts(), 'bankId'));

        $this->assertTrue(Bank::delete($account['id']));
        $this->assertCount($before, Bank::accounts());
    }

    public function testLabel()
    {
        $this->loginAs(self::ADMIN);

        // Trimmed, control characters replaced
        $account = Bank::create('222', 'bnp', "  Compte\tjoint ");
        $this->assertSame('Compte joint', $account['label']);
        $listed = array_column(Bank::accounts(), 'label', 'bankId');
        $this->assertSame('Compte joint', $listed['222@bnp']);
        $this->assertSame('Compte joint', array_column(Bank::get(), 'label', 'bankId')['222@bnp']);

        // Omitted (older clients): kept; empty: removed
        $this->assertSame('Compte joint', Bank::update($account['id'], '222', 'bnp')['label']);
        $this->assertSame('Livret A', Bank::update($account['id'], '222', 'bnp', 'Livret A')['label']);
        $this->assertSame('Livret A', array_column(Bank::accounts(), 'label', 'bankId')['222@bnp']);
        $this->assertNull(Bank::update($account['id'], '222', 'bnp', ' ')['label']);
        $this->assertNull(array_column(Bank::accounts(), 'label', 'bankId')['222@bnp']);
        $this->assertNull(Bank::create('333', 'bnp')['label']);

        // 100 characters at most (accents count as one)
        $this->assertSame(str_repeat('é', 100), Bank::update($account['id'], '222', 'bnp', str_repeat('é', 100))['label']);
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::update($account['id'], '222', 'bnp', str_repeat('é', 101));
    }

    public function testCreateDuplicateAndInvalid()
    {
        $this->loginAs(self::ADMIN);
        try {
            Bank::create('111', 'bnp');
            $this->fail('Duplicate accepted');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }

        // Never starting with "-": woob would read "-x@bnp" as an option; inside, kept
        foreach ([['-x', 'bnp'], ['--debug', 'bnp'], ['111', '-bnp']] as [$account, $bank]) {
            try {
                Bank::create($account, $bank);
                $this->fail("Accepted $account@$bank");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), $e->getMessage());
            }
        }
        $this->assertSame('12-3@bnp-2', Bank::create('12-3', 'bnp-2')['bankId']);

        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::create('1; rm -rf /', 'bnp');
    }

    public function testUserCannotManageAccounts()
    {
        $this->loginAs(self::USER);
        $own = Bank::get()[0]['id'];

        $this->assertAdminOnly(['GET /bank/accounts', 'POST /bank', 'PUT /bank', 'DELETE /bank', 'GET /bank/backends',
            'GET /bank/modules', 'GET /bank/discover', 'GET /bank/discover/interactive', 'GET /bank/module', 'POST /bank/backend',
            'PUT /bank/backend']);

        // Refused before the method is called: the account is still there
        try {
            $this->callRoute('DELETE', '/bank', ['id' => $own]);
            $this->fail('Deleted by a user');
        } catch (Error $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertSame($own, Bank::get()[0]['id']);
    }

    public function testDeleteUnknownAccount()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Bank::delete(99999);
    }

    public function testUpdateToAnAlreadyFollowedAccount()
    {
        $this->loginAs(self::ADMIN);
        $accounts = Bank::get();

        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        Bank::update($accounts[1]['id'], $accounts[0]['account_number'], $accounts[0]['bank_name']);
    }

    public function testBackends()
    {
        $this->loginAs(self::ADMIN);

        // Name and module only: the woob configuration (bank login) never leaves the server
        $this->assertSame([['name' => 'bnp', 'module' => 'bnp', 'label' => null], ['name' => 'cic_pro', 'module' => 'cic', 'label' => null]], Bank::backends());
    }

    public function testRenameBackend()
    {
        $this->loginAs(self::ADMIN);

        $this->assertSame(['name' => 'cic_pro', 'label' => 'CIC pro'], Bank::renameBackend('cic_pro', "  CIC\npro "));
        $this->assertSame(['name' => 'bnp', 'label' => 'Compte joint'], Bank::renameBackend('bnp', 'Compte joint'));
        $this->assertSame(['name' => 'bnp', 'label' => 'Compte joint ✨'], Bank::renameBackend('bnp', 'Compte joint ✨'));
        $this->assertSame(['Compte joint ✨', 'CIC pro'], array_column(Bank::backends(), 'label'));

        // An empty name removes it: the bank name is shown again
        $this->assertSame(['name' => 'cic_pro', 'label' => null], Bank::renameBackend('cic_pro', ' '));
        $this->assertSame(['Compte joint ✨', null], array_column(Bank::backends(), 'label'));
        $this->assertSame(1, (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_backend", "")['n']);

        foreach ([['Bad Name', 'x', 400], ['-bnp', 'x', 400], ['bnp', str_repeat('a', 101), 400], ['unknown', 'x', 404]] as [$backend, $label, $code]) {
            try {
                Bank::renameBackend($backend, $label);
                $this->fail("Accepted $backend");
            } catch (Error $e) {
                $this->assertSame($code, $e->getCode(), $e->getMessage());
            }
        }
    }

    public function testModules()
    {
        $this->loginAs(self::ADMIN);
        $this->assertSame([
            ['module' => 'bnp', 'description' => 'BNP Paribas'],
            ['module' => 'boursorama', 'description' => 'Boursorama'],
            ['module' => 'cic', 'description' => 'CIC'],
        ], Bank::modules());
    }

    public function testModuleFields()
    {
        $this->loginAs(self::ADMIN);
        $module = Bank::module('bnp');
        $this->assertSame('BNP Paribas', $module['description']);
        $fields = array_column($module['fields'], null, 'key');
        $this->assertTrue($fields['password']['masked']);
        $this->assertSame('^(\\d{6})$', $fields['password']['regexp']);
        $this->assertTrue($fields['login']['required']);
        $this->assertSame([['value' => 'pp', 'label' => 'Particuliers/Professionnels'], ['value' => 'hbank', 'label' => 'HelloBank']], $fields['website']['choices']);
        $this->assertSame('pp', $fields['website']['default']);
        // A false boolean stays "n" (not the first choice, which would enable the option)
        $this->assertSame('n', $fields['rotating_password']['default']);
        // No default (null): an empty value, optional
        $this->assertSame(['default' => '', 'required' => false], array_intersect_key($fields['identity'], ['default' => 1, 'required' => 1]));

        try {
            Bank::module('unknown');
            $this->fail('Unknown module accepted');
        } catch (Exception $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::module('../etc');
    }

    public function testModuleWoobCannotLoad()
    {
        $this->loginAs(self::ADMIN);
        // Repository trusted: woob installs the module
        $keyrings = WoobRepository::$keyringDir;
        @mkdir($keyrings, 0700, true);
        copy(dirname(__DIR__) . '/fixtures/woob-repository/trusted.gpg', "$keyrings/https___updates_woob_tech_3_main_.gpg");
        try {
            // A Python package of the module is missing: the cause of woob, not an empty form
            Bank::module('banquepopulaire');
            $this->fail('Module that woob cannot load described without settings');
        } catch (Error $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('woob cannot load the bank module banquepopulaire: Unable to load module "banquepopulaire": Module requires python package "python-jose" but not installed. '
                . 'Its Python packages are missing from the environment of woob', $e->getMessage());
        } finally {
            array_map('unlink', glob("$keyrings/*") ?: []);
        }
    }

    public function testModuleNotInstalledYet()
    {
        $this->loginAs(self::ADMIN);
        $installed = carbure_test_tmp('carbure-fake-woob-installed');
        @unlink($installed);
        $keyrings = WoobRepository::$keyringDir;
        array_map('unlink', glob("$keyrings/*") ?: []);
        try {
            // Not before the woob repository is trusted: woob would trust it by itself
            try {
                Bank::module('boursorama');
                $this->fail('Module installed before the woob repository is trusted');
            } catch (Error $e) {
                $this->assertSame(409, $e->getCode());
                $this->assertStringContainsString('Trust the woob repository first', $e->getMessage());
            }
            // Nor by connecting a bank of this module (woob config add would install it)
            try {
                Bank::createBackend('boursorama', 'boursorama', ['login' => '12345678', 'password' => '123456']);
                $this->fail('Bank of a module not installed connected before the woob repository is trusted');
            } catch (Error $e) {
                $this->assertSame(409, $e->getCode());
            }
            $this->assertFileDoesNotExist($installed);
            $this->assertFalse(WoobRepository::isTrusted());
            // An installed module needs nothing
            $this->assertNotEmpty(Bank::module('bnp')['fields']);

            // Trusted: woob describes the settings once the module is installed, Carbure installs it
            @mkdir($keyrings, 0700, true);
            copy(dirname(__DIR__) . '/fixtures/woob-repository/trusted.gpg', "$keyrings/https___updates_woob_tech_3_main_.gpg");
            $fields = array_column(Bank::module('boursorama')['fields'], 'key');
            $this->assertContains('password', $fields);
            $this->assertFileExists($installed);
        } finally {
            @unlink($installed);
            array_map('unlink', glob("$keyrings/*") ?: []);
        }
    }

    public function testCreateBackend()
    {
        $this->loginAs(self::ADMIN);
        $added = carbure_test_tmp('carbure-fake-woob-backends');
        file_put_contents($added, "[other]\n_module = cic\nlogin = jdoe\n");

        try {
            $result = Bank::createBackend('bnp', 'mybnp', ['login' => '12345678', 'password' => '123456', 'unknown' => 'x']);
            $this->assertSame('mybnp', $result['backend']);
            $this->assertNull($result['label']);
            $this->assertFalse($result['two_factor']);
            $this->assertSame(['DEMO0000001@mybnp', '00087654321@mybnp'], array_column($result['accounts'], 'bankId'));
            // Every setting of the module is given (defaults included; an optional one left
            // empty is not, woob refusing it: identity), in the configuration file of woob as
            // "woob config add" writes it (never on its command line: the fake woob refuses it),
            // readable by its owner only, the other backends kept
            $this->assertSame("[other]\n_module = cic\nlogin = jdoe\n"
                . "[mybnp]\n_module = bnp\nlogin = 12345678\npassword = 123456\nrotating_password = n\nwebsite = pp\n\n",
                file_get_contents($added));
            $this->assertSame(0600, fileperms($added) & 0777);
        } finally {
            @unlink($added);
        }
    }

    public function testCreateBackendWithName()
    {
        $this->loginAs(self::ADMIN);
        // The name of a former connection of the same woob name is replaced
        Db::execute("INSERT INTO bank_backend (name, label) VALUES ('mybnp', 'Ancien')", "");
        try {
            $result = Bank::createBackend('bnp', 'mybnp', ['login' => '12345678', 'password' => '123456'], ' Compte joint ');
            $this->assertSame('Compte joint', $result['label']);
            $this->assertSame('Compte joint', Db::queryOne("SELECT label FROM bank_backend WHERE name = ?", "s", 'mybnp')['label']);

            // Without a name, the former one is removed
            Db::execute("INSERT INTO bank_backend (name, label) VALUES ('mybnp3', 'Ancien')", "");
            Bank::createBackend('bnp', 'mybnp3', ['login' => '12345678', 'password' => '123456']);
            $this->assertNull(Db::queryOne("SELECT label FROM bank_backend WHERE name = ?", "s", 'mybnp3'));

            // An optional setting given is written
            Bank::createBackend('bnp', 'mybnp4', ['login' => '12345678', 'password' => '123456', 'identity' => 'A1B2']);
            $this->assertStringContainsString("[mybnp4]\n_module = bnp\nlogin = 12345678\npassword = 123456\nrotating_password = n\nwebsite = pp\nidentity = A1B2\n",
                (string)file_get_contents(carbure_test_tmp('carbure-fake-woob-backends')));
        } finally {
            @unlink(carbure_test_tmp('carbure-fake-woob-backends'));
        }

        // A name too long is refused before woob is called
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::createBackend('bnp', 'mybnp4', ['login' => '12345678', 'password' => '123456'], str_repeat('a', 101));
    }

    public function testDeleteBackend()
    {
        $removed = carbure_test_tmp('carbure-fake-woob-removed');
        @unlink($removed);
        $this->loginAs(self::ADMIN);
        Bank::renameBackend('bnp', 'Compte joint');
        $transactions = (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n'];

        try {
            $this->assertTrue(Bank::deleteBackend('bnp'));

            // Forgotten by woob, with its name and its followed accounts (of every owner)
            $this->assertSame("bnp\n", file_get_contents($removed));
            $this->assertSame([['name' => 'cic_pro', 'module' => 'cic', 'label' => null]], Bank::backends());
            $this->assertNull(Db::queryOne("SELECT name FROM bank_backend WHERE name = ?", "s", 'bnp'));
            $this->assertSame(['fail@bank'], array_column(Bank::accounts(), 'bankId'));
            // The transactions already imported are kept
            $this->assertSame($transactions, (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n']);

            // Already removed: unknown
            foreach ([['bnp', 404], ['Bad Name', 400], ['-b', 400]] as [$backend, $code]) {
                try {
                    Bank::deleteBackend($backend);
                    $this->fail("Removed $backend");
                } catch (Error $e) {
                    $this->assertSame($code, $e->getCode(), $e->getMessage());
                }
            }
        } finally {
            @unlink($removed);
        }
    }

    public function testDeleteBackendKeptByWoob()
    {
        $stuck = carbure_test_tmp('carbure-fake-woob-stuck');
        touch($stuck);
        $this->loginAs(self::ADMIN);
        try {
            Bank::deleteBackend('cic_pro');
            $this->fail('Removed while woob kept it');
        } catch (Error $e) {
            $this->fail('Unexpected client error: ' . $e->getMessage());
        } catch (Exception $e) {
            $this->assertStringContainsString('woob could not remove the bank cic_pro', $e->getMessage());
        } finally {
            @unlink($stuck);
        }
    }

    public function testDeleteBackendRequiresAnAdministrator()
    {
        $this->loginAs(self::USER);
        $this->assertAdminOnly(['DELETE /bank/backend']);
    }

    public function testCreateBackendWrongCredentials()
    {
        $this->loginAs(self::ADMIN);
        $removed = carbure_test_tmp('carbure-fake-woob-removed');
        @unlink($removed);
        try {
            Bank::createBackend('bnp', 'mybnp', ['login' => 'wrong', 'password' => '123456']);
            $this->fail('Wrong credentials accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringStartsWith('The bank refused the credentials, check them: ', $e->getMessage());
            $this->assertInstanceOf(ApiError::class, $e);
            $this->assertSame('bank_credentials_refused', $e->getErrorCode());
            $this->assertSame('bnp', $e->getParams()['module']);
            $this->assertStringContainsString('Identifiant ou mot de passe incorrect', $e->getMessage());
            // Removed from woob, to try again, with its credentials
            $this->assertSame('mybnp', trim(file_get_contents($removed)));
            $this->assertStringNotContainsString('mybnp', (string)file_get_contents(carbure_test_tmp('carbure-fake-woob-backends')));
        } finally {
            @unlink($removed);
            @unlink(carbure_test_tmp('carbure-fake-woob-backends'));
        }
    }

    public function testCreateBackendKeepsABankAskingATwoFactorAuthentication()
    {
        $this->loginAs(self::ADMIN);
        $removed = carbure_test_tmp('carbure-fake-woob-removed');
        @unlink($removed);
        try {
            // The bank asks a two-factor authentication, which woob only starts on a terminal
            // (#299): the bank stays configured, its accounts to list interactively
            $result = Bank::createBackend('bnp', 'my2fa', ['login' => '2fa', 'password' => '123456'], 'Crédit Mutuel');
            $this->assertSame(['backend' => 'my2fa', 'label' => 'Crédit Mutuel', 'accounts' => [], 'two_factor' => true], $result);
            $this->assertFileDoesNotExist($removed);
            $this->assertStringContainsString("[my2fa]\n_module = bnp\nlogin = 2fa\n", (string)file_get_contents(carbure_test_tmp('carbure-fake-woob-backends')));
            $this->assertSame('Crédit Mutuel', Db::queryOne("SELECT label FROM bank_backend WHERE name = ?", "s", 'my2fa')['label']);
        } finally {
            @unlink($removed);
            @unlink(carbure_test_tmp('carbure-fake-woob-backends'));
        }
    }

    public function testCreateBackendBlockedByTheBank()
    {
        $this->loginAs(self::ADMIN);
        $removed = carbure_test_tmp('carbure-fake-woob-removed');
        @unlink($removed);
        try {
            // The bank answers 403 to woob: not a matter of credentials
            Bank::createBackend('bnp', 'forbidden', ['login' => '12345678', 'password' => '123456']);
            $this->fail('Connection blocked by the bank accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringStartsWith('The bank blocks woob or the woob module "bnp" is broken', $e->getMessage());
            $this->assertStringContainsString('403 Client Error: Forbidden', $e->getMessage());
            $this->assertStringNotContainsString('credentials', $e->getMessage());
            $this->assertSame('forbidden', trim(file_get_contents($removed)));
        } finally {
            @unlink($removed);
            @unlink(carbure_test_tmp('carbure-fake-woob-backends'));
        }
    }

    public function testCreateBackendValidation()
    {
        $this->loginAs(self::ADMIN);
        // A backend of another module (not a bank one: not listed by woob) of the same name
        file_put_contents(carbure_test_tmp('carbure-fake-woob-backends'), "[taken]\n_module = other\n");
        foreach ([
            ['bnp', 'new', ['password' => '123456']],                                 // login missing
            ['bnp', 'new', ['login' => '1 2', 'password' => '123456']],               // space
            ['bnp', 'new', ['login' => '1"2', 'password' => '123456']],               // quote
            ['bnp', 'new', ['login' => '1', 'password' => 'abc']],                    // not 6 digits
            ['bnp', 'new', ['login' => '1', 'password' => '123456', 'website' => 'nope']], // not a choice
            ['bnp', 'New Bank', ['login' => '1', 'password' => '123456']],            // name
            ['bnp', 'taken', ['login' => '1', 'password' => '123456']],               // in the file of woob
            ['bnp', '-b', ['login' => '1', 'password' => '123456']],                  // read as an option
        ] as [$module, $backend, $settings]) {
            try {
                Bank::createBackend($module, $backend, $settings);
                $this->fail("Accepted $backend " . json_encode($settings));
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), $e->getMessage());
            }
        }

        // Nothing added to the file of woob
        $this->assertSame("[taken]\n_module = other\n", file_get_contents(carbure_test_tmp('carbure-fake-woob-backends')));
        unlink(carbure_test_tmp('carbure-fake-woob-backends'));

        // A bank already configured in woob
        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        Bank::createBackend('cic', 'cic_pro', []);
    }

    public function testSettingsAreNeverLogged()
    {
        $masked = Webservice::maskSensitive(['module' => 'bnp', 'settings' => ['login' => '123', 'pin' => '0000']]);
        $this->assertSame('***', $masked['settings']);
        $this->assertSame('bnp', $masked['module']);
    }

    public function testDiscover()
    {
        @unlink(carbure_test_tmp('carbure-fake-woob-backends'));
        $this->loginAs(self::ADMIN);
        $accounts = Bank::discover();

        $this->assertCount(2, $accounts);
        $this->assertSame('DEMO0000001@bnp', $accounts[0]['bankId']);
        $this->assertSame('Compte chèques', $accounts[0]['label']);
        $this->assertFalse($accounts[0]['followed']);

        Bank::create('DEMO0000001', 'bnp');
        $this->assertTrue(Bank::discover()[0]['followed']);
    }

    public function testDiscoverTheAccountsOfOneBank()
    {
        $this->loginAs(self::ADMIN);
        Bank::create('DEMO0000001', 'cic_pro');

        // woob bank list -b cic_pro: only the accounts of this bank connection
        $accounts = Bank::discover('cic_pro');
        $this->assertSame(['DEMO0000001@cic_pro', '00087654321@cic_pro'], array_column($accounts, 'bankId'));
        $this->assertSame(['cic_pro'], array_values(array_unique(array_column($accounts, 'bank_name'))));
        $this->assertSame([true, false], array_column($accounts, 'followed'));

        // Empty: every bank, as before
        $this->assertSame('bnp', Bank::discover('')[0]['bank_name']);

        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::discover('cic pro; id');
    }

    /**
     * Run the interactive discovery of the accounts of a bank and return the SSE output.
     */
    private function discoverInteractive(string $backend): string
    {
        ob_start();
        try {
            Bank::discoverInteractive($backend);
        } finally {
            $output = ob_get_clean();
        }
        return $output;
    }

    public function testDiscoverInteractiveRelaysTheTwoFactorAuthentication()
    {
        @unlink(carbure_test_tmp('carbure-fake-woob-backends'));
        $this->loginAs(self::ADMIN);
        Bank::create('DEMO0000001', '2fa');

        // Without a terminal, woob refuses to start the authentication
        try {
            Bank::discover('2fa');
            $this->fail('Two-factor authentication started without a terminal');
        } catch (Exception $e) {
            $this->assertTrue(Woob::needsTwoFactor($e->getMessage()));
        }

        // On a terminal: the message of the bank, then its accounts (the followed one marked)
        $output = $this->discoverInteractive('2fa');
        $this->assertStringContainsString("data: Bank message 2fa: Veuillez valider la connexion dans votre application bancaire.\n\n", $output);
        $this->assertSame(1, preg_match('/^data: Accounts (.+)$/m', $output, $m));
        $accounts = json_decode($m[1], true);
        $this->assertSame(['DEMO0000001@2fa', '00087654321@2fa'], array_column($accounts, 'bankId'));
        $this->assertSame(['Compte chèques', 'Livret A'], array_column($accounts, 'label'));
        $this->assertSame([true, false], array_column($accounts, 'followed'));
        $this->assertStringNotContainsString('Discovery failed', $output);
    }

    public function testDiscoverInteractiveFailures()
    {
        $this->loginAs(self::ADMIN);

        // The bank refuses woob: the cause, as the last event
        $this->assertStringEndsWith("data: Discovery failed: woob returned no account: Bug(forbidden): 403 Client Error: Forbidden\n\n",
            $this->discoverInteractive('forbidden'));

        // Never while a synchronization runs woob (another connection holds the lock)
        $other = new mysqli(Config::get('db_hostname'), Config::get('db_username'), Config::get('db_password'), Config::get('db_name'), (int)Config::get('db_port'));
        $other->query("SELECT GET_LOCK('bank_sync', 0)");
        try {
            $this->assertSame("data: Discovery failed: synchronization in progress\n\n", $this->discoverInteractive('2fa'));
        } finally {
            $other->close();
        }

        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::discoverInteractive('-2fa');
    }

    public function testSyncRecordsTheLastResultOfEachAccount()
    {
        $this->loginAs(self::ADMIN);
        $this->sync();

        $accounts = array_column(Bank::accounts(), null, 'bankId');
        $this->assertSame('OK', $accounts['111@bnp']['last_sync_status']);
        $this->assertNotNull($accounts['111@bnp']['last_sync_at']);
        $this->assertSame('ERROR', $accounts['fail@bank']['last_sync_status']);
        $this->assertNotEmpty($accounts['fail@bank']['last_sync_message']);
    }

    public function testSyncOneAccount()
    {
        $this->loginAs(self::ADMIN);
        ob_start();
        try {
            Bank::getSync(null, '111@bnp');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('Syncing 111@bnp', $output);
        $this->assertStringNotContainsString('fail@bank', $output);
        // The system command of each step, in the progress: the accounts listed first (#43)
        $this->assertMatchesRegularExpression("/data: Syncing 111@bnp \\(accounts\\).*data: Woob command 111@bnp: .* bank list -b 'bnp' -f json -s id"
            . ".*data: Syncing 111@bnp \\(coming\\)/s", $output);
        $this->assertMatchesRegularExpression("/data: Woob command 111@bnp: .* bank coming '111@bnp' --count/", $output);
        $this->assertMatchesRegularExpression("/data: Woob command 111@bnp: .* bank history '111@bnp' --count/", $output);

        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Bank::getSync(null, 'unknown@bnp');
    }

    public function testAnAccountNotListedByTheBankIsNotSynchronized()
    {
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bnp', 'unlisted', " . self::ADMIN . ")");
        $this->loginAs(self::ADMIN);
        ob_start();
        try {
            Bank::getSync(null, 'unlisted@bnp');
        } finally {
            $output = ob_get_clean();
        }

        // Neither its coming transactions nor its history asked: the error of the list
        $this->assertStringContainsString('data: Error syncing unlisted@bnp: Account unlisted@bnp not in the 16 account(s) listed by woob for bnp', $output);
        $this->assertStringNotContainsString('(coming)', $output);
        $this->assertStringNotContainsString('(history)', $output);
        $accounts = array_column(Bank::accounts(), null, 'bankId');
        $this->assertSame('ERROR', $accounts['unlisted@bnp']['last_sync_status']);
    }

    public function testInteractiveSyncRelaysTheTwoFactorAuthentication()
    {
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', '2fa', " . self::ADMIN . ")");
        $this->loginAs(self::ADMIN);

        // Without the interactive mode, woob refuses to start the authentication
        ob_start();
        try {
            Bank::getSync(null, '2fa@bank');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('data: Error syncing 2fa@bank: Error while calling woob (exit code: 1): Error(bank): You have to run bank in interactive mode', $output);
        // The history would ask the same validation: not tried
        $this->assertStringNotContainsString('Syncing 2fa@bank (history)', $output);

        // The household is asked to approve the authentication in the portal (the device is
        // of the administrator: French)
        $alerts = array_map(fn($r) => $r['payload']['aps']['alert'], FakePushRelay::requests());
        $this->assertContains(['title' => 'Authentification forte requise', 'body' => 'Ouvrez Carbure (Comptes) pour approuver la connexion à BANK'],
            array_map(fn($a) => array_intersect_key($a, ['title' => 1, 'body' => 1]), $alerts));

        ob_start();
        try {
            Bank::getSync(null, '2fa@bank', 'true');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('data: Bank message 2fa@bank: Veuillez valider la connexion dans votre application bancaire.', $output);
        $this->assertStringContainsString('data: Done 2fa@bank (history): 3 received', $output);
        $this->assertStringNotContainsString('Error syncing', $output);
    }

    /**
     * Run the synchronization of one account for the scheduler (sync token), with the
     * waits replaced: returns the SSE output and the number of seconds waited.
     */
    private function scheduledSync(string $account, int $retries, ?callable $onWait = null): array
    {
        Config::set('sync_token', 'scheduler-secret');
        Config::set('woob_2fa_wait', 2);
        Config::set('woob_2fa_retries', $retries);
        $waited = 0;
        Bank::$sleep = function (int $seconds) use (&$waited, $onWait) {
            $waited += $seconds;
            if ($onWait) {
                $onWait();
            }
        };
        ob_start();
        try {
            Bank::getSync('scheduler-secret', $account);
        } finally {
            $output = ob_get_clean();
            Bank::$sleep = null;
            Config::set('sync_token', '');
            Config::set('woob_2fa_wait', 0);
            Config::set('woob_2fa_retries', 1);
        }
        // The results are then read by an administrator
        $this->loginAs(self::ADMIN);
        return [$output, $waited];
    }

    public function testDeletedTransactionNotSynchronizedAgain()
    {
        Db::query("DELETE FROM bank_transaction");
        $this->loginAs(self::ADMIN);
        $sync = function () {
            ob_start();
            try {
                Bank::getSync(null, '111@bnp');
            } finally {
                ob_end_clean();
            }
        };
        $sync();
        $count = (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n'];
        $this->assertGreaterThan(1, $count);

        // A transaction deleted by a user does not come back at the next synchronization
        $deleted = Db::queryOne("SELECT id, uuid FROM bank_transaction ORDER BY id LIMIT 1", "");
        Transaction::delete($deleted['id']);
        $sync();
        $this->assertSame($count - 1, (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n']);
        $this->assertNull(Db::queryOne("SELECT id FROM bank_transaction WHERE uuid = ?", "s", $deleted['uuid']));
    }

    public function testSyncReadsTheLabelAndCompletesTheTransactionsSavedWithoutOne()
    {
        Db::query("DELETE FROM bank_transaction");
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'labelonly', " . self::ADMIN . ")");
        [$byHand, $byRule] = array_map('intval', array_column(Db::select("SELECT id FROM bank_transaction_category WHERE id > 0 ORDER BY id LIMIT 2")->fetch_all(MYSQLI_ASSOC), 'id'));
        Db::execute("INSERT INTO bank_transaction_category_keyword (keyword, category) VALUES ('CARREFOUR', ?)", "i", $byRule);
        // Saved without a label by a previous version: the module only fills label (#328)
        $month = date('Y-m');
        $insert = "INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, pointed, bank_name, account_number, user)
                   VALUES (?, ?, ?, ?, '', ?, ?, ?, 'bank', 'labelonly', ?)";
        Db::execute($insert, "sssiidii", 'old-edf', "$month-02", "$month-02", 2, $byHand, -60.1, 1, self::ADMIN);
        Db::execute($insert, "sssiidii", 'old-carrefour', "$month-03", "$month-03", 7, 0, -12.5, 0, self::ADMIN);
        $ids = array_column(Db::select("SELECT id, uuid FROM bank_transaction")->fetch_all(MYSQLI_ASSOC), 'id', 'uuid');

        $this->loginAs(self::ADMIN);
        $sync = function () {
            ob_start();
            try {
                Bank::getSync(null, 'labelonly@bank');
            } finally {
                $output = ob_get_clean();
            }
            return $output;
        };
        $output = $sync();

        // The label of woob when raw is empty; the two transactions completed, not saved again
        $this->assertStringContainsString('data: CB CARREFOUR', $output);
        $this->assertStringContainsString('data: Done labelonly@bank (history): 3 received, 1 new', $output);
        $rows = array_column(Db::select("SELECT id, label, category, pointed, card FROM bank_transaction ORDER BY rdate")->fetch_all(MYSQLI_ASSOC), null, 'label');
        $this->assertSame(['PRLV SEPA EDF', 'CB CARREFOUR', ''], array_keys($rows));
        $this->assertSame([$ids['old-edf'], $byHand, 1], [$rows['PRLV SEPA EDF']['id'], (int)$rows['PRLV SEPA EDF']['category'], (int)$rows['PRLV SEPA EDF']['pointed']]);
        $this->assertSame([$ids['old-carrefour'], $byRule, '4974XXXXXXXX1234'], [$rows['CB CARREFOUR']['id'], (int)$rows['CB CARREFOUR']['category'], $rows['CB CARREFOUR']['card']]);

        // Nothing duplicated at the next synchronization (the one without any label included)
        $this->assertStringContainsString('data: Done labelonly@bank (history): 3 received, 0 new', $sync());
        $this->assertSame(3, (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n']);
    }

    public function testComingFailureKeepsTheHistory()
    {
        Db::query("DELETE FROM bank_transaction");
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'nocoming', " . self::ADMIN . ")");
        $this->loginAs(self::ADMIN);
        ob_start();
        try {
            Bank::getSync(null, 'nocoming@bank');
        } finally {
            $output = ob_get_clean();
        }

        // The failure of the coming transactions is shown, the history still synchronized
        $this->assertStringContainsString('data: Coming failed nocoming@bank: Error while calling woob (exit code: 1): Error(bank): NotImplementedError: coming transactions are not available', $output);
        $this->assertStringContainsString('data: Done nocoming@bank (history): 3 received, 3 new', $output);
        $this->assertStringNotContainsString('Error syncing', $output);
        $this->assertStringContainsString('data: Updating missing categories...', $output);

        // The account is synchronized, with a note
        $account = array_column(Bank::accounts(), null, 'bankId')['nocoming@bank'];
        $this->assertSame('OK', $account['last_sync_status']);
        $this->assertStringStartsWith('3 new (coming failed: Error while calling woob', $account['last_sync_message']);
        $titles = array_map(fn($r) => $r['payload']['aps']['alert']['title'], FakePushRelay::requests());
        $this->assertContains(Messages::t('fr', 'sync_success_title'), $titles);
        $this->assertNotContains(Messages::t('fr', 'sync_error_title'), $titles);

        // The history fails too: the account fails
        $this->loginAs(self::ADMIN);
        ob_start();
        try {
            Bank::getSync(null, 'fail@bank');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('data: Coming failed fail@bank', $output);
        $this->assertStringContainsString('data: Syncing fail@bank (history)...', $output);
        $this->assertStringContainsString('data: Error syncing fail@bank', $output);
        $this->assertSame('ERROR', array_column(Bank::accounts(), null, 'bankId')['fail@bank']['last_sync_status']);
    }

    public function testScheduledSyncRetriesAfterAValidation()
    {
        Db::query("DELETE FROM bank_transaction");
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'validate', " . self::ADMIN . ")");
        $validated = carbure_test_tmp('carbure-fake-woob-validated');
        @unlink($validated);
        try {
            // The validation is done in the bank app during the wait
            [$output, $waited] = $this->scheduledSync('validate@bank', 1, fn() => touch($validated));
        } finally {
            @unlink($validated);
        }

        $this->assertStringContainsString('data: Error syncing validate@bank: Error while calling woob (exit code: 1): Error(bank): You have to run bank in interactive mode', $output);
        $this->assertStringContainsString('data: Waiting for a validation validate@bank: new attempt in 2 s (1/1)', $output);
        $this->assertStringContainsString(': heartbeat', $output);
        $this->assertStringContainsString('data: Done validate@bank (history): 3 received, 3 new', $output);
        $this->assertSame(2, $waited);

        $account = array_column(Bank::accounts(), null, 'bankId')['validate@bank'];
        $this->assertSame('OK', $account['last_sync_status']);
        $this->assertSame('4 new', $account['last_sync_message']);
        // Only the result of the last attempt is notified
        $titles = array_map(fn($r) => $r['payload']['aps']['alert']['title'], FakePushRelay::requests());
        $this->assertContains(Messages::t('fr', 'sync_success_title'), $titles);
        $this->assertNotContains(Messages::t('fr', 'sync_2fa_title'), $titles);
    }

    public function testScheduledSyncRecordsTheValidationWhenTheRetriesRunOut()
    {
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'validate', " . self::ADMIN . ")");
        @unlink(carbure_test_tmp('carbure-fake-woob-validated'));

        [$output, $waited] = $this->scheduledSync('validate@bank', 2);
        $this->assertStringContainsString('data: Waiting for a validation validate@bank: new attempt in 2 s (1/2)', $output);
        $this->assertStringContainsString('data: Waiting for a validation validate@bank: new attempt in 2 s (2/2)', $output);
        $this->assertSame(3, substr_count($output, 'data: Error syncing validate@bank'));
        $this->assertSame(4, $waited);

        // The household is asked to approve the connection in the portal, once
        $account = array_column(Bank::accounts(), null, 'bankId')['validate@bank'];
        $this->assertSame('ERROR', $account['last_sync_status']);
        $this->assertTrue(Woob::needsTwoFactor($account['last_sync_message']));
        $titles = array_map(fn($r) => $r['payload']['aps']['alert']['title'], FakePushRelay::requests());
        $this->assertSame(1, count(array_filter($titles, fn($t) => $t === Messages::t('fr', 'sync_2fa_title'))));

        // No retry: woob_2fa_retries=0
        [$output, $waited] = $this->scheduledSync('validate@bank', 0);
        $this->assertStringNotContainsString('Waiting for a validation', $output);
        $this->assertSame(0, $waited);
    }

    public function testSyncOfAUserDoesNotRetryAfterAValidation()
    {
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'validate', " . self::ADMIN . ")");
        @unlink(carbure_test_tmp('carbure-fake-woob-validated'));
        $waits = 0;
        Bank::$sleep = function () use (&$waits) {
            $waits++;
        };
        Config::set('woob_2fa_wait', 2);
        try {
            // A user in the portal, interactive or not: never waits
            foreach ([null, 'true'] as $interactive) {
                $this->loginAs(self::ADMIN);
                $output = '';
                ob_start();
                try {
                    Bank::getSync(null, 'validate@bank', $interactive);
                } finally {
                    $output = ob_get_clean();
                }
                $this->assertStringContainsString('data: Error syncing validate@bank', $output);
                $this->assertStringNotContainsString('Waiting for a validation', $output);
            }
        } finally {
            Bank::$sleep = null;
            Config::set('woob_2fa_wait', 0);
        }
        $this->assertSame(0, $waits);
    }

    public function testSyncSendsTheDebugLogsOfWoobToAnAdministrator()
    {
        Config::set('woob_debug', true);
        try {
            $this->loginAs(self::ADMIN);
            $output = $this->sync();
            $this->assertStringContainsString('data: Woob debug 111@bnp: DEBUG:woob.browser:Request: GET https://bank.example/accounts', $output);
            $this->assertStringContainsString("data: Woob debug fail@bank: AttributeError: 'NoneType' object has no attribute 'iter_accounts'", $output);

            // The debug logs may show bank data: not for the other users
            $this->loginAs(self::USER);
            $this->assertStringNotContainsString('Woob debug', $this->sync());
        } finally {
            Config::set('woob_debug', false);
        }

        // Disabled: no debug logs
        $this->loginAs(self::ADMIN);
        $this->assertStringNotContainsString('Woob debug', $this->sync());
    }

    public function testDiscoverReportsTheRefusalOfTheBank()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('woob returned no account: Bug(forbidden): 403 Client Error: Forbidden');
        Bank::discover('forbidden');
    }

    public function testSyncRecordsTheRefusalOfTheBank()
    {
        $this->loginAs(self::ADMIN);
        Db::execute("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'forbidden', ?)", "i", self::ADMIN);
        ob_start();
        try {
            Bank::getSync(null, 'forbidden@bank');
        } finally {
            $output = ob_get_clean();
        }

        // Not "0 new": the error of the bank
        $this->assertStringContainsString('data: Error syncing forbidden@bank: woob returned no data: Bug(bank): 403 Client Error: Forbidden', $output);
        $account = array_column(Bank::accounts(), null, 'bankId')['forbidden@bank'];
        $this->assertSame('ERROR', $account['last_sync_status']);
        $this->assertStringContainsString('403 Client Error: Forbidden', $account['last_sync_message']);
    }

    public function testFailedSyncDoesNotUpdateTheCategories()
    {
        $this->loginAs(self::ADMIN);
        ob_start();
        try {
            Bank::getSync(null, 'fail@bank');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('data: Error syncing fail@bank', $output);
        $this->assertStringContainsString('data: Notifying users of fail@bank...', $output);
        $this->assertStringNotContainsString('Updating missing categories', $output);
        $this->assertStringContainsString('data: Synchronization complete', $output);

        // One account synchronized: the categories are updated
        $this->assertStringContainsString('data: Updating missing categories...', $this->sync());
    }

    public function testInteractiveSyncNeedsAUserAndOneAccount()
    {
        $this->loginAs(self::ADMIN);
        try {
            Bank::getSync(null, null, 'true');
            $this->fail('All the accounts in interactive mode');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }

        // The scheduler (sync token, checked first) never starts a two-factor authentication
        Config::set('sync_token', 'scheduler-secret');
        try {
            $this->expectException(Error::class);
            $this->expectExceptionCode(400);
            Bank::getSync('scheduler-secret', '111@bnp', 'true');
        } finally {
            Config::set('sync_token', '');
        }
    }

    public function testSync()
    {
        Db::query("DELETE FROM bank_transaction");
        $this->loginAs(self::ADMIN);

        $output = $this->sync();

        $this->assertStringContainsString('data: Syncing 111@bnp (coming)...', $output);
        $this->assertStringContainsString('data: Done 111@bnp (coming): 1 received, 1 new', $output);
        $this->assertStringContainsString('data: Done 111@bnp (history): 3 received, 3 new', $output);
        $this->assertStringContainsString('data: Error syncing fail@bank', $output);
        $this->assertStringContainsString('data: Synchronization complete', $output);

        // coming: only the pending card payment (type 12) is kept; history: 3 transactions
        $rows = Db::query("SELECT * FROM bank_transaction ORDER BY rdate")->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(4, $rows);
        $this->assertEquals([self::ADMIN], array_values(array_unique(array_column($rows, 'user'))));

        // Labels are cleaned and categorized from the keywords
        $labels = array_column($rows, 'category', 'label');
        $this->assertEquals(2, $labels['CB SUPERMARCHE']);
        $this->assertEquals(3, $labels['PRLV SEPA EDF ']);
        $this->assertEquals(4, $labels['VIR SALAIRE']);

        // A second synchronization does not duplicate anything
        $this->sync();
        $this->assertSame(4, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n']);

        // The admin device is notified for each of his accounts (success and failure)
        $titles = array_map(fn($r) => $r['payload']['aps']['alert']['title'], FakePushRelay::requests());
        $this->assertContains(Messages::t('fr', 'sync_success_title'), $titles);
        $this->assertContains(Messages::t('fr', 'sync_error_title'), $titles);
    }

    public function testLargeExpenseAlert()
    {
        Db::query("DELETE FROM bank_transaction");
        Db::query("UPDATE users SET alert_threshold = 50 WHERE id = 1");
        $this->loginAs(self::ADMIN);

        $this->sync();

        // Only the new expense of 60.10 reaches the threshold (42.50, 25 and the income do not)
        $alerts = array_values(array_filter(FakePushRelay::requests(),
            fn($r) => $r['payload']['aps']['alert']['title'] === 'Dépense importante à vérifier'));
        $this->assertCount(1, $alerts);
        // With the real date of the transaction
        $this->assertMatchesRegularExpression('#^PRLV SEPA EDF : -60,10 € le \d{2}/\d{2}/\d{4}$#', $alerts[0]['payload']['aps']['alert']['body']);

        // Known transactions do not trigger the alert again
        FakePushRelay::start();
        $this->sync();
        $titles = array_map(fn($r) => $r['payload']['aps']['alert']['title'], FakePushRelay::requests());
        $this->assertNotContains('Dépense importante à vérifier', $titles);
    }

    public function testLargeExpenseAlertInEnglishAndDisabled()
    {
        Db::query("UPDATE users SET alert_threshold = 10, language = 'en' WHERE id = 1");

        $this->assertSame(1, Bank::alertLargeExpenses('111@bnp', [['label' => 'CB SHOP', 'amount' => -12.5]]));
        $this->assertSame('CB SHOP: -12.50 €', FakePushRelay::requests()[0]['payload']['aps']['alert']['body']);
        $this->assertSame(0, Bank::alertLargeExpenses('111@bnp', []));

        Db::query("UPDATE users SET alert_threshold = NULL");
        $this->assertSame(0, Bank::alertLargeExpenses('111@bnp', [['label' => 'CB SHOP', 'amount' => -500]]));
    }

    /**
     * Entries of the log of the tests containing a text
     */
    private function logEntries(string $text): array
    {
        Logger::flush();
        $file = Config::get('data_dir') . '/logs/carbure_test_' . date('Ymd') . '.log';
        return array_values(array_filter(is_file($file) ? file($file) : [], fn($line) => str_contains($line, $text)));
    }

    public function testSyncWithToken()
    {
        $deprecated = 'The sync token passed in the URL is deprecated';
        Config::set('sync_token', 'scheduler-secret');
        try {
            // The header: no deprecation warning
            $before = count($this->logEntries($deprecated));
            $_SERVER['HTTP_X_SYNC_TOKEN'] = 'scheduler-secret';
            $this->assertStringContainsString('Synchronization complete', $this->sync());
            $this->assertCount($before, $this->logEntries($deprecated));

            // The header wins over the query parameter
            $this->assertStringContainsString('Synchronization complete', $this->sync('wrong'));
            $this->assertCount($before, $this->logEntries($deprecated));
            unset($_SERVER['HTTP_X_SYNC_TOKEN']);

            // The query parameter still works (schedulers already configured), with one
            // warning that never contains the token
            $this->assertStringContainsString('Synchronization complete', $this->sync('scheduler-secret'));
            $entries = $this->logEntries($deprecated);
            $this->assertCount($before + 1, $entries);
            $entry = json_decode(end($entries), true);
            $this->assertSame('WARNING', $entry['level']);
            $this->assertSame('The sync token passed in the URL is deprecated, use the X-Sync-Token header', $entry['message']);
            $this->assertStringNotContainsString('scheduler-secret', end($entries));
        } finally {
            unset($_SERVER['HTTP_X_SYNC_TOKEN']);
            Config::set('sync_token', '');
        }
    }

    public function testDeprecationHeadersOfTheTokenInTheUrl()
    {
        // RFC 9745: a date (@ + Unix time) and the page of the header to use instead
        $this->assertSame(['Deprecation: @1792108800', 'Link: <https://carbure.org/api/#bank-sync>; rel="deprecation"; type="text/html"'],
            Bank::TOKEN_IN_URL_HEADERS);
        $this->assertSame('2026-10-16T00:00:00+00:00', gmdate('c', 1792108800));
    }

    public function testSyncRefusedWithoutCredentials()
    {
        Config::set('sync_token', 'scheduler-secret');
        try {
            $this->expectException(Error::class);
            $this->expectExceptionCode(401);
            $this->sync('wrong');
        } finally {
            Config::set('sync_token', '');
        }
    }

    public function testSyncRefusedWithAWrongHeader()
    {
        Config::set('sync_token', 'scheduler-secret');
        $_SERVER['HTTP_X_SYNC_TOKEN'] = 'wrong';
        try {
            // Same error as a wrong token in the URL, and the right token in the URL does
            // not replace a wrong header
            $this->expectException(Error::class);
            $this->expectExceptionCode(401);
            $this->expectExceptionMessage('Unauthorized - Invalid or missing JWT token or sync token');
            $this->sync('scheduler-secret');
        } finally {
            unset($_SERVER['HTTP_X_SYNC_TOKEN']);
            Config::set('sync_token', '');
        }
    }

    public function testSyncRefusedWithAnEmptyToken()
    {
        // No token configured: an empty token never matches
        Config::set('sync_token', '');
        $_SERVER['HTTP_X_SYNC_TOKEN'] = '';
        try {
            $this->expectException(Error::class);
            $this->expectExceptionCode(401);
            $this->sync('');
        } finally {
            unset($_SERVER['HTTP_X_SYNC_TOKEN']);
        }
    }

    public function testSyncAlreadyRunning()
    {
        $this->loginAs(self::ADMIN);

        // Another connection holds the lock
        $other = new mysqli(Config::get('db_hostname'), Config::get('db_username'), Config::get('db_password'), Config::get('db_name'), (int)Config::get('db_port'));
        $other->query("SELECT GET_LOCK('bank_sync', 0)");
        try {
            $this->assertSame("data: Synchronization already in progress\n\n", $this->sync());
        } finally {
            $other->close();
        }
    }

    public function testSyncAccountWithoutTransactions()
    {
        Db::query("DELETE FROM bank_account");
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'empty', 2)");
        $this->loginAs(self::USER);

        $output = $this->sync();

        $this->assertStringContainsString('Notifying users of empty@bank', $output);
        $this->assertStringContainsString('Done empty@bank (history): 0 received, 0 new', $output);
        $this->assertSame(0, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE user=2", "")['n']);
    }

    public function testRuleNotification()
    {
        Db::query("UPDATE bank_transaction_category_keyword SET notify = 1 WHERE keyword = 'EDF'");
        $sent = Bank::alertRuleMatches([
            ['label' => 'PRLV SEPA EDF', 'amount' => -50.0],
            ['label' => 'CB BOULANGERIE', 'amount' => -3.2],
        ]);
        // One user of the data set has a device; every user is notified
        $this->assertSame((int)Db::queryOne("SELECT COUNT(*) AS n FROM users", "")['n'], $sent);
        $this->assertNotEmpty(FakePushRelay::requests());
        $this->assertSame(0, Bank::alertRuleMatches([]));
    }

    public function testRuleWithAmountNotifiesOnlyItsAmount()
    {
        Db::query("INSERT INTO bank_transaction_category_keyword (keyword, category, notify, amount) VALUES ('PAYPAL', 1, 1, 10.00)");
        $this->assertSame(0, Bank::alertRuleMatches([['label' => 'PAYPAL EUROPE', 'amount' => -25.0]]));
        $this->assertGreaterThan(0, Bank::alertRuleMatches([['label' => 'PAYPAL EUROPE', 'amount' => -10.0]]));
    }

    public function testNotifyEveryUserOfTheHousehold()
    {
        // The devices of every user are notified, whoever added the account
        Bank::notifyBankUsers('fail@bank', true);
        $this->assertNotEmpty(FakePushRelay::requests());
    }

    public function testOwnerOfUnknownAccount()
    {
        $this->expectException(Exception::class);
        (new ReflectionMethod(Bank::class, 'getBankOwner'))->invoke(null, 'unknown@bank');
    }

    /**
     * Update woob as the administrator and return the SSE output (the keyrings trusted
     * by the fake woob are forgotten before).
     */
    private function updateWoob(?string $fingerprint = null, bool $forget = true): string
    {
        if ($forget) {
            array_map('unlink', glob(WoobRepository::$keyringDir . '/*') ?: []);
        }
        ob_start();
        try {
            Bank::updateWoob($fingerprint);
        } finally {
            $output = ob_get_clean();
        }
        return $output;
    }

    public function testWoobRepositoryTrustedByAnAdministrator()
    {
        $this->loginAs(self::ADMIN);
        $fingerprint = sha1_file(dirname(__DIR__) . '/fixtures/woob-repository/trusted.gpg');
        try {
            // Not trusted: the fingerprint to compare is given
            array_map('unlink', glob(WoobRepository::$keyringDir . '/*') ?: []);
            $status = Bank::woob();
            $this->assertFalse($status['trusted']);
            $this->assertSame($fingerprint, $status['fingerprint']);

            // No fingerprint, or another one: woob (which would trust any keyring by
            // itself) is not even started, nothing trusted
            foreach ([null, str_repeat('a', 40)] as $confirmed) {
                $output = $this->updateWoob($confirmed);
                $this->assertSame("data: Woob repository not trusted: $fingerprint\n\n", $output);
                $this->assertFalse(Bank::woob()['trusted']);
            }

            // The fingerprint of the keyring: trusted, then the modules are updated
            $output = $this->updateWoob(strtoupper($fingerprint));
            $this->assertStringContainsString("data: Fingerprint of keyring is $fingerprint", $output);
            $this->assertStringContainsString('data: 100% All modules are up-to-date.', $output);
            $this->assertStringEndsWith("data: Woob updated\n\n", $output);
            $this->assertSame(['trusted' => true, 'fingerprint' => $fingerprint], array_intersect_key(Bank::woob(), ['trusted' => 1, 'fingerprint' => 1]));

            // Trusted: no question any more
            $output = $this->updateWoob(null, false);
            $this->assertStringNotContainsString('Fingerprint of keyring', $output);
            $this->assertStringEndsWith("data: Woob updated\n\n", $output);

            // woob got another keyring than the one confirmed: removed, the update fails
            touch(carbure_test_tmp('carbure-fake-woob-other-keyring'));
            $output = $this->updateWoob($fingerprint);
            $this->assertStringContainsString('data: Woob update failed: keyring', $output);
            $this->assertStringContainsString("is not the confirmed one ($fingerprint)", $output);
            $this->assertFalse(Bank::woob()['trusted']);
        } finally {
            @unlink(carbure_test_tmp('carbure-fake-woob-other-keyring'));
            array_map('unlink', glob(WoobRepository::$keyringDir . '/*') ?: []);
        }
    }

    public function testFreshWoobNotRunBeforeItsRepositoryIsTrusted()
    {
        $this->loginAs(self::ADMIN);
        $sources = WoobRepository::$sourcesList;
        @unlink($sources);
        array_map('unlink', glob(WoobRepository::$keyringDir . '/*') ?: []);
        try {
            // Any woob command would trust the keyring of its repository by itself
            // (the list of the modules may come from the cache: woob not run either)
            foreach ([fn() => Bank::backends(), fn() => Bank::module('bnp'), fn() => Bank::discover()] as $i => $call) {
                try {
                    $call();
                    $this->fail("Case $i: woob run before its repository is trusted");
                } catch (Error $e) {
                    $this->assertSame(409, $e->getCode(), "Case $i");
                }
            }
            $this->assertFalse(Bank::woob()['trusted']);
            $this->assertFileDoesNotExist($sources);

            // Trusted by the administrator: woob runs
            $fingerprint = sha1_file(dirname(__DIR__) . '/fixtures/woob-repository/trusted.gpg');
            $this->assertStringEndsWith("data: Woob updated\n\n", $this->updateWoob($fingerprint, false));
            $this->assertSame(['bnp', 'cic_pro'], array_column(Bank::backends(), 'name'));
        } finally {
            file_put_contents($sources, "https://updates.woob.tech/%(version)s/main/\n");
            array_map('unlink', glob(WoobRepository::$keyringDir . '/*') ?: []);
        }
    }

    public function testWoobUpdateNotDuringASynchronization()
    {
        $this->loginAs(self::ADMIN);
        // The synchronization of another connection holds the lock
        $other = new mysqli(Config::get('db_hostname'), Config::get('db_username'), Config::get('db_password'), Config::get('db_name'), (int)Config::get('db_port'));
        $other->query("SELECT GET_LOCK('bank_sync', 0)");
        try {
            $this->assertSame("data: Woob update failed: synchronization in progress\n\n", $this->updateWoob());
        } finally {
            $other->close();
        }
    }

    public function testWoobUpdateInvalidFingerprint()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::updateWoob('not-a-sha1');
    }

    public function testWoobRequiresAnAdministrator()
    {
        $this->loginAs(self::USER);
        // The stream (Server-Sent Events) is refused before it starts
        $this->assertAdminOnly(['GET /bank/woob', 'GET /bank/woob/update']);
    }
}
