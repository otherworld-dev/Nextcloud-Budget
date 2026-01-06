<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Report;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\CategoryMapper;

/**
 * Aggregates data to generate summary reports.
 */
class ReportAggregator {
    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;
    private CategoryMapper $categoryMapper;
    private ReportCalculator $calculator;

    public function __construct(
        AccountMapper $accountMapper,
        TransactionMapper $transactionMapper,
        CategoryMapper $categoryMapper,
        ReportCalculator $calculator
    ) {
        $this->accountMapper = $accountMapper;
        $this->transactionMapper = $transactionMapper;
        $this->categoryMapper = $categoryMapper;
        $this->calculator = $calculator;
    }

    /**
     * Generate a comprehensive financial summary.
     */
    public function generateSummary(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate
    ): array {
        $accounts = $accountId
            ? [$this->accountMapper->find($accountId, $userId)]
            : $this->accountMapper->findAll($userId);

        $summary = [
            'period' => [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'days' => (strtotime($endDate) - strtotime($startDate)) / (24 * 60 * 60)
            ],
            'accounts' => [],
            'totals' => [
                'currentBalance' => 0,
                'totalIncome' => 0,
                'totalExpenses' => 0,
                'netIncome' => 0,
                'averageDaily' => [
                    'income' => 0,
                    'expenses' => 0
                ]
            ],
            'spending' => [],
            'trends' => []
        ];

        $totalIncome = 0;
        $totalExpenses = 0;

        foreach ($accounts as $account) {
            $accountTransactions = $this->transactionMapper->findByDateRange(
                $account->getId(),
                $startDate,
                $endDate
            );

            $accountIncome = 0;
            $accountExpenses = 0;

            foreach ($accountTransactions as $transaction) {
                if ($transaction->getType() === 'credit') {
                    $accountIncome += $transaction->getAmount();
                } else {
                    $accountExpenses += $transaction->getAmount();
                }
            }

            $summary['accounts'][] = [
                'id' => $account->getId(),
                'name' => $account->getName(),
                'balance' => $account->getBalance(),
                'currency' => $account->getCurrency(),
                'income' => $accountIncome,
                'expenses' => $accountExpenses,
                'net' => $accountIncome - $accountExpenses,
                'transactionCount' => count($accountTransactions)
            ];

            $summary['totals']['currentBalance'] += $account->getBalance();
            $totalIncome += $accountIncome;
            $totalExpenses += $accountExpenses;
        }

        $summary['totals']['totalIncome'] = $totalIncome;
        $summary['totals']['totalExpenses'] = $totalExpenses;
        $summary['totals']['netIncome'] = $totalIncome - $totalExpenses;

        $days = $summary['period']['days'];
        if ($days > 0) {
            $summary['totals']['averageDaily']['income'] = $totalIncome / $days;
            $summary['totals']['averageDaily']['expenses'] = $totalExpenses / $days;
        }

        // Get spending breakdown
        $summary['spending'] = $this->transactionMapper->getSpendingSummary(
            $userId,
            $startDate,
            $endDate
        );

        // Generate trend data
        $summary['trends'] = $this->generateTrendData($userId, $accountId, $startDate, $endDate);

        return $summary;
    }

    /**
     * Generate summary with comparison to previous period.
     */
    public function generateSummaryWithComparison(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate
    ): array {
        // Current period
        $current = $this->generateSummary($userId, $accountId, $startDate, $endDate);

        // Calculate previous period (same duration)
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $interval = $start->diff($end);

        $prevEnd = clone $start;
        $prevEnd->modify('-1 day');
        $prevStart = clone $prevEnd;
        $prevStart->sub($interval);

        $previous = $this->generateSummary(
            $userId,
            $accountId,
            $prevStart->format('Y-m-d'),
            $prevEnd->format('Y-m-d')
        );

        // Calculate changes
        $current['comparison'] = [
            'previousPeriod' => [
                'startDate' => $prevStart->format('Y-m-d'),
                'endDate' => $prevEnd->format('Y-m-d')
            ],
            'changes' => [
                'income' => $this->calculator->calculatePercentChange(
                    $previous['totals']['totalIncome'] ?? 0,
                    $current['totals']['totalIncome'] ?? 0
                ),
                'expenses' => $this->calculator->calculatePercentChange(
                    $previous['totals']['totalExpenses'] ?? 0,
                    $current['totals']['totalExpenses'] ?? 0
                ),
                'netIncome' => $this->calculator->calculatePercentChange(
                    $previous['totals']['netIncome'] ?? 0,
                    $current['totals']['netIncome'] ?? 0
                )
            ],
            'previousTotals' => $previous['totals'] ?? []
        ];

        return $current;
    }

    /**
     * Generate budget report with category-by-category breakdown.
     */
    public function getBudgetReport(string $userId, string $startDate, string $endDate): array {
        $categories = $this->categoryMapper->findAll($userId);
        $budgetReport = [];
        $totals = [
            'budgeted' => 0,
            'spent' => 0,
            'remaining' => 0
        ];

        foreach ($categories as $category) {
            if ($category->getBudgetAmount() > 0) {
                $spent = $this->categoryMapper->getCategorySpending(
                    $category->getId(),
                    $startDate,
                    $endDate
                );

                $budgeted = $category->getBudgetAmount();
                $remaining = $budgeted - $spent;
                $percentage = $budgeted > 0 ? ($spent / $budgeted) * 100 : 0;

                $budgetReport[] = [
                    'categoryId' => $category->getId(),
                    'categoryName' => $category->getName(),
                    'budgeted' => $budgeted,
                    'spent' => $spent,
                    'remaining' => $remaining,
                    'percentage' => $percentage,
                    'status' => $this->calculator->getBudgetStatus($percentage),
                    'color' => $category->getColor()
                ];

                $totals['budgeted'] += $budgeted;
                $totals['spent'] += $spent;
                $totals['remaining'] += $remaining;
            }
        }

        return [
            'period' => [
                'startDate' => $startDate,
                'endDate' => $endDate
            ],
            'categories' => $budgetReport,
            'totals' => $totals,
            'overallStatus' => $this->calculator->getBudgetStatus(
                $totals['budgeted'] > 0 ? ($totals['spent'] / $totals['budgeted']) * 100 : 0
            )
        ];
    }

    /**
     * Generate cash flow report by month.
     */
    public function getCashFlowReport(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate
    ): array {
        $cashFlow = $this->transactionMapper->getCashFlowByMonth($userId, $accountId, $startDate, $endDate);

        $totals = ['income' => 0, 'expenses' => 0, 'net' => 0];
        foreach ($cashFlow as $month) {
            $totals['income'] += $month['income'];
            $totals['expenses'] += $month['expenses'];
            $totals['net'] += $month['net'];
        }

        $monthCount = count($cashFlow);

        return [
            'period' => ['startDate' => $startDate, 'endDate' => $endDate],
            'data' => $cashFlow,
            'totals' => $totals,
            'averageMonthly' => [
                'income' => $monthCount > 0 ? $totals['income'] / $monthCount : 0,
                'expenses' => $monthCount > 0 ? $totals['expenses'] / $monthCount : 0,
                'net' => $monthCount > 0 ? $totals['net'] / $monthCount : 0
            ]
        ];
    }

    /**
     * Generate monthly trend data for charts.
     */
    public function generateTrendData(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate
    ): array {
        $trends = [
            'labels' => [],
            'income' => [],
            'expenses' => []
        ];

        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $interval = new \DateInterval('P1M');

        $current = clone $start;
        while ($current <= $end) {
            $monthStart = $current->format('Y-m-01');
            $monthEnd = $current->format('Y-m-t');

            $trends['labels'][] = $current->format('M Y');

            // Get transactions for this month
            $monthIncome = 0;
            $monthExpenses = 0;

            $accounts = $accountId
                ? [$this->accountMapper->find($accountId, $userId)]
                : $this->accountMapper->findAll($userId);

            foreach ($accounts as $account) {
                $transactions = $this->transactionMapper->findByDateRange(
                    $account->getId(),
                    $monthStart,
                    $monthEnd
                );

                foreach ($transactions as $transaction) {
                    if ($transaction->getType() === 'credit') {
                        $monthIncome += $transaction->getAmount();
                    } else {
                        $monthExpenses += $transaction->getAmount();
                    }
                }
            }

            $trends['income'][] = $monthIncome;
            $trends['expenses'][] = $monthExpenses;

            $current->add($interval);
        }

        return $trends;
    }
}
