<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules\Processors;

use PlaidMonitor\Money;
use PlaidMonitor\Plaid\TransactionMapper;
use PlaidMonitor\Rules\Flag;
use PlaidMonitor\Rules\RuleProcessor;
use PlaidMonitor\Rules\Severity;
use PlaidMonitor\Storage\Integration;

/**
 * Flags transactions in any of the configured Plaid personal finance
 * categories. Both primary (e.g. ENTERTAINMENT) and detailed
 * (e.g. ENTERTAINMENT_CASINOS_AND_GAMBLING) codes are accepted.
 */
class TransactionCategory implements RuleProcessor
{
    public const LABELS = [
        'INCOME_DIVIDENDS' => 'Dividends',
        'INCOME_INTEREST_EARNED' => 'Interest Earned',
        'INCOME_RETIREMENT_PENSION' => 'Pension',
        'INCOME_TAX_REFUND' => 'Tax Refunds',
        'INCOME_UNEMPLOYMENT' => 'Unemployment',
        'INCOME_WAGES' => 'Wages & Salary',
        'INCOME_OTHER_INCOME' => 'Other Income',
        'TRANSFER_IN_CASH_ADVANCES_AND_LOANS' => 'Cash Advances In',
        'TRANSFER_IN_DEPOSIT' => 'Deposits',
        'TRANSFER_IN_INVESTMENT_AND_RETIREMENT_FUNDS' => 'Investment Transfers In',
        'TRANSFER_IN_SAVINGS' => 'Savings Transfers In',
        'TRANSFER_IN_ACCOUNT_TRANSFER' => 'Account Transfers In',
        'TRANSFER_IN_OTHER_TRANSFER_IN' => 'Other Transfers In',
        'TRANSFER_OUT_INVESTMENT_AND_RETIREMENT_FUNDS' => 'Investment Transfers Out',
        'TRANSFER_OUT_SAVINGS' => 'Savings Transfers Out',
        'TRANSFER_OUT_WITHDRAWAL' => 'Withdrawals',
        'TRANSFER_OUT_ACCOUNT_TRANSFER' => 'Account Transfers Out',
        'TRANSFER_OUT_OTHER_TRANSFER_OUT' => 'Other Transfers Out',
        'LOAN_PAYMENTS_CAR_PAYMENT' => 'Car Payments',
        'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT' => 'Credit Card Payments',
        'LOAN_PAYMENTS_PERSONAL_LOAN_PAYMENT' => 'Personal Loans',
        'LOAN_PAYMENTS_MORTGAGE_PAYMENT' => 'Mortgage Payments',
        'LOAN_PAYMENTS_STUDENT_LOAN_PAYMENT' => 'Student Loans',
        'LOAN_PAYMENTS_OTHER_PAYMENT' => 'Other Debt Payments',
        'BANK_FEES_ATM_FEES' => 'ATM Fees',
        'BANK_FEES_FOREIGN_TRANSACTION_FEES' => 'Foreign Transaction Fees',
        'BANK_FEES_INSUFFICIENT_FUNDS' => 'Insufficient Funds',
        'BANK_FEES_INTEREST_CHARGE' => 'Interest Charges',
        'BANK_FEES_OVERDRAFT_FEES' => 'Overdraft Fees',
        'BANK_FEES_OTHER_BANK_FEES' => 'Other Bank Fees',
        'ENTERTAINMENT_CASINOS_AND_GAMBLING' => 'Gambling & Casinos',
        'ENTERTAINMENT_MUSIC_AND_AUDIO' => 'Music & Audio',
        'ENTERTAINMENT_SPORTING_EVENTS_AMUSEMENT_PARKS_AND_MUSEUMS' => 'Events & Museums',
        'ENTERTAINMENT_TV_AND_MOVIES' => 'TV & Movies',
        'ENTERTAINMENT_VIDEO_GAMES' => 'Video Games',
        'ENTERTAINMENT_OTHER_ENTERTAINMENT' => 'Other Entertainment',
        'FOOD_AND_DRINK_BEER_WINE_AND_LIQUOR' => 'Alcohol',
        'FOOD_AND_DRINK_COFFEE' => 'Coffee Shops',
        'FOOD_AND_DRINK_FAST_FOOD' => 'Fast Food',
        'FOOD_AND_DRINK_GROCERIES' => 'Groceries',
        'FOOD_AND_DRINK_RESTAURANT' => 'Restaurants',
        'FOOD_AND_DRINK_VENDING_MACHINES' => 'Vending Machines',
        'FOOD_AND_DRINK_OTHER_FOOD_AND_DRINK' => 'Other Food & Drink',
        'GENERAL_MERCHANDISE_BOOKSTORES_AND_NEWSSTANDS' => 'Books & News',
        'GENERAL_MERCHANDISE_CLOTHING_AND_ACCESSORIES' => 'Clothing',
        'GENERAL_MERCHANDISE_CONVENIENCE_STORES' => 'Convenience Stores',
        'GENERAL_MERCHANDISE_DEPARTMENT_STORES' => 'Department Stores',
        'GENERAL_MERCHANDISE_DISCOUNT_STORES' => 'Discount Stores',
        'GENERAL_MERCHANDISE_ELECTRONICS' => 'Electronics',
        'GENERAL_MERCHANDISE_GIFTS_AND_NOVELTIES' => 'Gifts & Cards',
        'GENERAL_MERCHANDISE_OFFICE_SUPPLIES' => 'Office Supplies',
        'GENERAL_MERCHANDISE_ONLINE_MARKETPLACES' => 'Online Shopping',
        'GENERAL_MERCHANDISE_PET_SUPPLIES' => 'Pet Supplies',
        'GENERAL_MERCHANDISE_SPORTING_GOODS' => 'Sporting Goods',
        'GENERAL_MERCHANDISE_SUPERSTORES' => 'Superstores',
        'GENERAL_MERCHANDISE_TOBACCO_AND_VAPE' => 'Tobacco & Vape',
        'GENERAL_MERCHANDISE_OTHER_GENERAL_MERCHANDISE' => 'Other Merchandise',
        'HOME_IMPROVEMENT_FURNITURE' => 'Furniture',
        'HOME_IMPROVEMENT_HARDWARE' => 'Hardware',
        'HOME_IMPROVEMENT_REPAIR_AND_MAINTENANCE' => 'Home Repair',
        'HOME_IMPROVEMENT_SECURITY' => 'Home Security',
        'HOME_IMPROVEMENT_OTHER_HOME_IMPROVEMENT' => 'Other Home',
        'MEDICAL_DENTAL_CARE' => 'Dental Care',
        'MEDICAL_EYE_CARE' => 'Eye Care',
        'MEDICAL_NURSING_CARE' => 'Nursing Care',
        'MEDICAL_PHARMACIES_AND_SUPPLEMENTS' => 'Pharmacies',
        'MEDICAL_PRIMARY_CARE' => 'Doctors',
        'MEDICAL_VETERINARY_SERVICES' => 'Veterinary',
        'MEDICAL_OTHER_MEDICAL' => 'Other Medical',
        'PERSONAL_CARE_GYMS_AND_FITNESS_CENTERS' => 'Gyms & Fitness',
        'PERSONAL_CARE_HAIR_AND_BEAUTY' => 'Hair & Beauty',
        'PERSONAL_CARE_LAUNDRY_AND_DRY_CLEANING' => 'Laundry',
        'PERSONAL_CARE_OTHER_PERSONAL_CARE' => 'Other Personal Care',
        'GENERAL_SERVICES_ACCOUNTING_AND_FINANCIAL_PLANNING' => 'Accounting',
        'GENERAL_SERVICES_AUTOMOTIVE' => 'Automotive',
        'GENERAL_SERVICES_CHILDCARE' => 'Childcare',
        'GENERAL_SERVICES_CONSULTING_AND_LEGAL' => 'Consulting & Legal',
        'GENERAL_SERVICES_EDUCATION' => 'Education',
        'GENERAL_SERVICES_INSURANCE' => 'Insurance',
        'GENERAL_SERVICES_POSTAGE_AND_SHIPPING' => 'Shipping',
        'GENERAL_SERVICES_STORAGE' => 'Storage',
        'GENERAL_SERVICES_OTHER_GENERAL_SERVICES' => 'Other Services',
        'GOVERNMENT_AND_NON_PROFIT_DONATIONS' => 'Donations',
        'GOVERNMENT_AND_NON_PROFIT_GOVERNMENT_DEPARTMENTS_AND_AGENCIES' => 'Government Agencies',
        'GOVERNMENT_AND_NON_PROFIT_TAX_PAYMENT' => 'Tax Payments',
        'GOVERNMENT_AND_NON_PROFIT_OTHER_GOVERNMENT_AND_NON_PROFIT' => 'Other Government',
        'TRANSPORTATION_BIKES_AND_SCOOTERS' => 'Bikes & Scooters',
        'TRANSPORTATION_GAS' => 'Gas Stations',
        'TRANSPORTATION_PARKING' => 'Parking',
        'TRANSPORTATION_PUBLIC_TRANSIT' => 'Public Transit',
        'TRANSPORTATION_TAXIS_AND_RIDE_SHARES' => 'Taxis & Rideshare',
        'TRANSPORTATION_TOLLS' => 'Tolls',
        'TRANSPORTATION_OTHER_TRANSPORTATION' => 'Other Transportation',
        'TRAVEL_FLIGHTS' => 'Flights',
        'TRAVEL_LODGING' => 'Hotels & Lodging',
        'TRAVEL_RENTAL_CARS' => 'Rental Cars',
        'TRAVEL_OTHER_TRAVEL' => 'Other Travel',
        'RENT_AND_UTILITIES_GAS_AND_ELECTRICITY' => 'Gas & Electric',
        'RENT_AND_UTILITIES_INTERNET_AND_CABLE' => 'Internet & Cable',
        'RENT_AND_UTILITIES_RENT' => 'Rent',
        'RENT_AND_UTILITIES_SEWAGE_AND_WASTE_MANAGEMENT' => 'Waste Management',
        'RENT_AND_UTILITIES_TELEPHONE' => 'Cell Phone',
        'RENT_AND_UTILITIES_WATER' => 'Water',
        'RENT_AND_UTILITIES_OTHER_UTILITIES' => 'Other Utilities',
    ];

    private const HIGH_RISK = [
        'ENTERTAINMENT_CASINOS_AND_GAMBLING',
        'GENERAL_MERCHANDISE_TOBACCO_AND_VAPE',
        'BANK_FEES_OVERDRAFT_FEES',
        'BANK_FEES_INSUFFICIENT_FUNDS',
        'TRANSFER_IN_CASH_ADVANCES_AND_LOANS',
        'LOAN_PAYMENTS_PERSONAL_LOAN_PAYMENT',
    ];

    private const MEDIUM_RISK = [
        'FOOD_AND_DRINK_BEER_WINE_AND_LIQUOR',
        'BANK_FEES_ATM_FEES',
        'BANK_FEES_FOREIGN_TRANSACTION_FEES',
        'TRANSFER_OUT_WITHDRAWAL',
        'ENTERTAINMENT_OTHER_ENTERTAINMENT',
    ];

    public function type(): string
    {
        return 'transaction_category';
    }

    public function evaluate(Integration $integration, array $transactions, array $config): array
    {
        $selected = array_map('strval', (array) ($config['categories'] ?? []));
        if ($selected === []) {
            return [];
        }

        $flags = [];

        foreach ($transactions as $transaction) {
            $detailed = $transaction['category']['detailed'] ?? null;
            $primary = $transaction['category']['primary'] ?? null;

            $matched = match (true) {
                $detailed !== null && in_array($detailed, $selected, true) => $detailed,
                $primary !== null && in_array($primary, $selected, true) => $primary,
                default => null,
            };

            if ($matched === null) {
                continue;
            }

            $merchant = TransactionMapper::merchant($transaction);
            $amount = abs((float) ($transaction['amount'] ?? 0));
            $label = self::LABELS[$matched] ?? $matched;

            $flags[] = new Flag(
                $transaction,
                sprintf(
                    'Monitored category "%s": %s (%s)',
                    $label,
                    $merchant,
                    Money::format($amount, (string) ($transaction['iso_currency_code'] ?? 'USD'))
                ),
                $this->severity($detailed ?? $matched),
                [
                    'merchant_name'  => $merchant,
                    'category'       => $matched,
                    'category_label' => $label,
                    'amount'         => $amount,
                ]
            );
        }

        return $flags;
    }

    private function severity(string $category): Severity
    {
        return match (true) {
            in_array($category, self::HIGH_RISK, true) => Severity::High,
            in_array($category, self::MEDIUM_RISK, true) => Severity::Medium,
            default => Severity::Low,
        };
    }
}
