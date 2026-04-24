<?php

namespace App\Console\Commands\Spendula;

use App\Enums\YnabAccountType;
use App\Models\BankAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('spendula:accounts:seed-mock
    {--bank-account-id= : Spendula bank_account UUID to map}
    {--ynab-account-id= : YNAB account UUID to map to}
    {--ynab-account-type=on_budget : on_budget or tracking}
    {--display-name= : Human-readable label stored on the bank_account row}
    {--import-cutoff-date= : YYYY-MM-DD; transactions before this date auto-skip}
')]
#[Description('One-off phase-1 mapper: wire a Spendula bank_account to a YNAB account.')]
class AccountsSeedMockCommand extends Command
{
    public function handle(): int
    {
        $bankAccountId = (string) $this->option('bank-account-id');
        $ynabAccountId = (string) $this->option('ynab-account-id');
        $ynabAccountTypeInput = (string) $this->option('ynab-account-type');
        $displayName = $this->option('display-name');
        $importCutoffDateInput = $this->option('import-cutoff-date');

        if ($bankAccountId === '' || $ynabAccountId === '') {
            $this->error('--bank-account-id and --ynab-account-id are required.');

            return self::FAILURE;
        }

        $account = BankAccount::query()->find($bankAccountId);
        if (! $account instanceof BankAccount) {
            $this->error("No bank_account with id={$bankAccountId}. Connect first via spendula:auth:start.");

            return self::FAILURE;
        }

        $ynabType = YnabAccountType::tryFrom($ynabAccountTypeInput);
        if ($ynabType === null) {
            $this->error("Invalid --ynab-account-type '{$ynabAccountTypeInput}'. Use 'on_budget' or 'tracking'.");

            return self::FAILURE;
        }

        if (! $account->is_base_currency && $ynabType === YnabAccountType::OnBudget) {
            $this->error(
                "Refusing to map non-base-currency account ({$account->currency}) to on_budget. "
                .'SPEC §4.3: foreign-currency accounts must map to tracking only. '
                .'Pass --ynab-account-type=tracking instead.'
            );

            return self::FAILURE;
        }

        if ($importCutoffDateInput === null) {
            $importCutoffDate = Carbon::today();
        } else {
            try {
                $importCutoffDate = Carbon::createFromFormat('!Y-m-d', (string) $importCutoffDateInput);
            } catch (\Throwable) {
                $importCutoffDate = null;
            }

            if (! $importCutoffDate instanceof Carbon) {
                $this->error("Invalid --import-cutoff-date '{$importCutoffDateInput}'. Expected YYYY-MM-DD.");

                return self::FAILURE;
            }
        }

        $account->ynab_account_id = $ynabAccountId;
        $account->ynab_account_type = $ynabType;
        $account->import_cutoff_date = $importCutoffDate;
        if (is_string($displayName) && $displayName !== '') {
            $account->display_name = $displayName;
        }
        $account->save();

        $this->info(sprintf(
            'Mapped bank_account=%s (%s) → ynab_account=%s as %s; cutoff=%s.',
            $account->id,
            $account->currency,
            $ynabAccountId,
            $ynabType->value,
            $account->import_cutoff_date->toDateString(),
        ));

        return self::SUCCESS;
    }
}
