<?php

use App\Http\Controllers\AgencySettingsController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AppointmentImportController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\Auth\ImisSsoController;
use App\Http\Controllers\Auth\ImisSsoLogoutController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\SessionHeartbeatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FieldOfficeController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ReferenceDocumentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportDeadlineController;
use App\Http\Controllers\ReportReviewController;
use App\Http\Controllers\ReportSubmissionController;
use App\Http\Controllers\SpecialGroupReportController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// There is no password sign-in: staff open this system from IMIS (see ImisSsoController).
Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::get('/sso', [ImisSsoController::class, 'show'])->middleware('throttle:30,1')->name('sso');
    Route::post('/sso', [ImisSsoController::class, 'store'])->middleware('throttle:30,1')->name('sso.store');
});

// IMIS tells us a person signed out of IMIS (single sign-out). Server-to-server: no cookie and
// no CSRF token, the secret `sid` is the only credential. Outside the guest/auth groups on purpose.
Route::post('/sso/logout', ImisSsoLogoutController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('sso.logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    // Idle-timeout keep-alive; account-level like logout.
    Route::post('/session/heartbeat', SessionHeartbeatController::class)->middleware('throttle:30,1')->name('session.heartbeat');

    // Appointments: encode, search, edit. Who may see or change each one is AppointmentPolicy.
    Route::get('/appointments/{appointment}/details', [AppointmentController::class, 'details'])->name('appointments.details');

    // Import from the MOA form: check the file first (saves nothing), then confirm.
    Route::get('/appointments/import/template', [AppointmentImportController::class, 'template'])->middleware('throttle:20,1')->name('appointments.import.template');
    Route::post('/appointments/import/preview', [AppointmentImportController::class, 'preview'])->middleware('throttle:20,1')->name('appointments.import.preview');
    Route::post('/appointments/import', [AppointmentImportController::class, 'commit'])->middleware('throttle:20,1')->name('appointments.import');
    Route::resource('appointments', AppointmentController::class)->except('show');

    // Qualification Standards: the standards, issuances, memorandums and policies that guide processing. Everyone reads;
    // PSED manages (the 'manage-references' gate, checked in the controller and request).
    Route::get('/qualification-standards', [ReferenceDocumentController::class, 'index'])->name('standards.index');
    Route::post('/qualification-standards', [ReferenceDocumentController::class, 'store'])->name('standards.store');
    Route::put('/qualification-standards/{reference}', [ReferenceDocumentController::class, 'update'])->name('standards.update');
    Route::delete('/qualification-standards/{reference}', [ReferenceDocumentController::class, 'destroy'])->name('standards.destroy');
    Route::get('/qualification-standards/{reference}/file', [ReferenceDocumentController::class, 'file'])->name('standards.file');
    Route::get('/lookups/positions', [LookupController::class, 'positions'])->middleware('throttle:120,1')->name('lookups.positions');

    // Monthly reports (Excel template hydration).
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/check', [ReportController::class, 'check'])->middleware('throttle:60,1')->name('reports.check');
    Route::get('/reports/download', [ReportController::class, 'download'])->middleware('throttle:20,1')->name('reports.download');

    // Field Offices submit their generated reports to PSED, the consolidator, who accepts or returns them.
    Route::get('/reports/submit', [ReportSubmissionController::class, 'index'])->name('reports.submit');
    Route::post('/reports/submit', [ReportSubmissionController::class, 'store'])->middleware('throttle:20,1')->name('reports.submit.store');
    Route::get('/reports/submissions', [ReportReviewController::class, 'index'])->name('reports.submissions');
    Route::get('/reports/submissions/download', [ReportReviewController::class, 'download'])->middleware('throttle:10,1')->name('reports.submissions.download');
    Route::get('/reports/submissions/{submission}/file', [ReportSubmissionController::class, 'file'])->whereNumber('submission')->name('reports.submissions.file');
    Route::post('/reports/submissions/{submission}/accept', [ReportReviewController::class, 'accept'])->whereNumber('submission')->name('reports.submissions.accept');
    Route::post('/reports/submissions/{submission}/return', [ReportReviewController::class, 'return'])->whereNumber('submission')->name('reports.submissions.return');

    // How many appointments were for PWDs, indigenous group members and solo parents, by period: PSED administrators only.
    Route::get('/statistics/special-groups', [SpecialGroupReportController::class, 'index'])->name('statistics.special-groups');
    Route::get('/statistics/special-groups/export', [SpecialGroupReportController::class, 'export'])->middleware('throttle:20,1')->name('statistics.special-groups.export');

    // Audit trail (who did what): PSED administrators only.
    Route::get('/audit-trail', [AuditTrailController::class, 'index'])->name('audit-trail.index');

    // Field Office settings: PSED administrators only.
    Route::get('/settings/field-offices', [FieldOfficeController::class, 'index'])->name('settings.field-offices');
    // When the Field Offices' monthly reports are due: PSED administrators only.
    Route::get('/settings/report-deadlines', [ReportDeadlineController::class, 'index'])->name('settings.deadlines');
    Route::put('/settings/report-deadlines', [ReportDeadlineController::class, 'update'])->name('settings.deadlines.update');
    Route::post('/settings/report-deadlines/months', [ReportDeadlineController::class, 'store'])->name('settings.deadlines.store');
    Route::delete('/settings/report-deadlines/months/{deadline}', [ReportDeadlineController::class, 'destroy'])->whereNumber('deadline')->name('settings.deadlines.destroy');

    // The position titles suggested on the appointment form: PSED administrators only.
    Route::get('/settings/positions', [PositionController::class, 'index'])->name('settings.positions');
    Route::post('/settings/positions', [PositionController::class, 'store'])->name('settings.positions.store');
    Route::put('/settings/positions/{position}', [PositionController::class, 'update'])->whereNumber('position')->name('settings.positions.update');
    Route::delete('/settings/positions/{position}', [PositionController::class, 'destroy'])->whereNumber('position')->name('settings.positions.destroy');

    // The agency list, brought in from the PSED directory: PSED administrators only.
    Route::get('/settings/agencies', [AgencySettingsController::class, 'index'])->name('settings.agencies');
    Route::put('/settings/agencies/{agency}', [AgencySettingsController::class, 'rename'])->whereNumber('agency')->name('settings.agencies.rename');
    Route::post('/settings/agencies/sync', [AgencySettingsController::class, 'sync'])->middleware('throttle:10,1')->name('settings.agencies.sync');
    Route::put('/settings/field-offices/{office}', [FieldOfficeController::class, 'update'])->name('settings.field-offices.update');
});
