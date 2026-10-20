<?php
/**
 * Test: K3 Audit Reporting & Export Logic
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../admin/laporan.php';

function testReporting(): void {
    echo "Running testReporting...\n";
    $pdo = getDbConnection();

    // 1. Uji fungsi pengambilan data laporan dengan filter
    $filters = [
        'start_date'  => date('Y-m-d', strtotime('-7 days')),
        'end_date'    => date('Y-m-d'),
        'status'      => 'ALL',
        'id_category' => 0
    ];

    $reportData = fetchAuditReportData($pdo, $filters);
    if (!isset($reportData['loans']) || !isset($reportData['summary'])) {
        throw new RuntimeException("FAIL: fetchAuditReportData did not return 'loans' and 'summary'.");
    }

    $summary = $reportData['summary'];
    if (!isset($summary['total_transaksi']) || !isset($summary['total_kembali_baik']) || !isset($summary['total_rusak'])) {
        throw new RuntimeException("FAIL: Summary report metrics missing required fields.");
    }

    // 2. Uji fungsi generator CSV
    $csvOutput = generateAuditCsv($reportData['loans']);
    if (empty($csvOutput) || !str_contains($csvOutput, 'KODE PINJAM') || !str_contains($csvOutput, 'STATUS')) {
        throw new RuntimeException("FAIL: CSV generator did not produce valid CSV with expected headers.");
    }

    echo "PASS: K3 audit reporting data aggregation and CSV export verified.\n";
}

try {
    testReporting();
    exit(0);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
