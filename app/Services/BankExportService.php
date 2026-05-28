<?php

namespace App\Services;

use App\Models\Payroll;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankExportService
{
    /**
     * تصدير ملف CSV بنكي
     */
    public static function exportCSV(Payroll $payroll): StreamedResponse
    {
        $payroll->load('items.employee.user');

        $filename = "payroll_{$payroll->payroll_number}_{$payroll->currency}.csv";

        return response()->streamDownload(function () use ($payroll) {
            $output = fopen('php://output', 'w');

            // BOM for UTF-8
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Header
            fputcsv($output, [
                'Employee Number', 'Employee Name', 'Bank Name', 'IBAN',
                'SWIFT', 'Net Salary', 'Currency', 'Description'
            ]);

            foreach ($payroll->items as $item) {
                $emp = $item->employee;
                fputcsv($output, [
                    $emp->employee_number,
                    $emp->user->name ?? '',
                    $emp->bank_name ?? '',
                    $emp->bank_account ?? '',
                    $emp->bank_swift ?? '',
                    number_format((float) $item->net_salary, 2, '.', ''),
                    $payroll->currency,
                    "Salary {$payroll->month}/{$payroll->year}",
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
