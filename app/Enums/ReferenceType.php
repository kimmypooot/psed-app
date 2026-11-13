<?php

namespace App\Enums;

/**
 * The category of a document on the Qualification Standards page: what the guidance that governs the
 * processing of appointments is. The order here is the order of the category bar and of the picker.
 */
enum ReferenceType: string
{
    /** The 2025 Omnibus Rules on Appointments and Other Human Resource Actions (ORA-OHRA). */
    case Ora2025 = 'ora_ohra_2025';
    case CscMemorandum = 'csc_memorandum';
    case ChedMemorandum = 'ched_memorandum';
    case DbmMemorandum = 'dbm_memorandum';
    case Law = 'law';
    case RulesRegulations = 'rules_regulations';
    case Policy = 'policy';
    case QualificationStandard = 'qualification_standard';

    // Kept for documents added before the categories above existed.
    case Issuance = 'issuance';
    case Memorandum = 'memorandum';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Ora2025 => '2025 ORA-OHRA',
            self::CscMemorandum => 'CSC Memorandum',
            self::ChedMemorandum => 'CHED Memorandum',
            self::DbmMemorandum => 'DBM Memorandum',
            self::Law => 'Law',
            self::RulesRegulations => 'Rules and Regulations',
            self::Policy => 'Policy',
            self::QualificationStandard => 'Qualification Standard',
            self::Issuance => 'Other issuance',
            self::Memorandum => 'Other memorandum',
            self::Other => 'Other guide',
        };
    }

    /** What belongs in the category, shown when adding a document. */
    public function description(): string
    {
        return match ($this) {
            self::Ora2025 => 'The 2025 Omnibus Rules on Appointments and Other Human Resource Actions, and the guides issued under it.',
            self::CscMemorandum => 'Civil Service Commission memorandum circulars and resolutions on appointments.',
            self::ChedMemorandum => 'Commission on Higher Education memorandum orders, such as the qualification requirements for teaching positions.',
            self::DbmMemorandum => 'Department of Budget and Management circulars on positions, salary grades and plantilla.',
            self::Law => 'Republic Acts, executive orders and presidential decrees that govern appointments.',
            self::RulesRegulations => 'Implementing rules and regulations, and other civil service rules on appointments.',
            self::Policy => 'Office policies and guidelines on how appointments are processed.',
            self::QualificationStandard => 'The qualification standards of positions: education, training, experience and eligibility.',
            self::Issuance => 'Other issuances that guide appointment processing.',
            self::Memorandum => 'Other memorandums that guide appointment processing.',
            self::Other => 'Anything else that guides appointment processing.',
        };
    }

    /** Documents that were filed before the specific categories: shown only while some are left. */
    public function isLegacy(): bool
    {
        return in_array($this, [self::Issuance, self::Memorandum, self::Other], true);
    }

    /** Badge colour (see Badge.vue tones). The label always says it too, so colour is never the only signal. */
    public function tone(): string
    {
        return match ($this) {
            self::QualificationStandard => 'success',
            self::Ora2025 => 'info',
            self::CscMemorandum => 'primary',
            self::ChedMemorandum, self::Memorandum => 'warning',
            self::DbmMemorandum, self::Issuance => 'info',
            self::Law, self::RulesRegulations, self::Policy, self::Other => 'neutral',
        };
    }
}
