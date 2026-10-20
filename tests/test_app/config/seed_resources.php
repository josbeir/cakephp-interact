<?php
declare(strict_types=1);

use Cake\Database\Connection;

/** @var \Cake\Database\Connection $connection */
$connection->execute(
    'CREATE TABLE IF NOT EXISTS resources (id INTEGER PRIMARY KEY, title TEXT NOT NULL, summary TEXT NOT NULL, category TEXT NOT NULL, level TEXT NOT NULL, minutes INTEGER NOT NULL)',
);
if ($connection->execute('SELECT COUNT(*) FROM resources')->fetchColumn(0) !== 0) {
    return;
}

// Seed once in a transaction so existing demo databases acquire the library without a reset.
$connection->transactional(static function (Connection $connection): void {
    $topics = [
        'cakephp' => [
            'Custom finders',
            'Entity validation',
            'Table associations',
            'Attribute routing',
            'Dependency injection',
            'Pagination',
            'Database transactions',
            'Query expressions',
            'View helpers',
            'Application middleware',
        ],
        'alpine' => [
            'Local state',
            'Browser events',
            'Conditional display',
            'Keyboard interactions',
            'Focus management',
            'DOM morphing',
            'Nested islands',
            'Reactive bindings',
            'CSP compatibility',
            'Lifecycle cleanup',
        ],
        'typescript' => [
            'Type narrowing',
            'Discriminated unions',
            'Generic functions',
            'Readonly state',
            'Module boundaries',
            'Async workflows',
            'DOM types',
            'Runtime validation',
            'Error handling',
            'Strict configuration',
        ],
        'forms' => [
            'Conditional fields',
            'Dependent choices',
            'Checkbox groups',
            'Input validation',
            'Draft preservation',
            'Multi-step forms',
            'Accessible labels',
            'Inline errors',
            'Explicit saves',
            'Numeric inputs',
        ],
        'security' => [
            'CSRF protection',
            'Snapshot signatures',
            'Authorization checks',
            'Session boundaries',
            'Output escaping',
            'Content security policy',
            'Safe action arguments',
            'Input allowlists',
            'SQL parameters',
            'Request isolation',
        ],
        'testing' => [
            'PHPUnit assertions',
            'Vitest fixtures',
            'Playwright locators',
            'Browser interception',
            'Coverage gates',
            'Fake timers',
            'Integration tests',
            'SQLite fixtures',
            'Race conditions',
            'Regression tests',
        ],
        'performance' => [
            'Query pagination',
            'Database indexes',
            'Debounced search',
            'Request coalescing',
            'Selective rendering',
            'Cache boundaries',
            'Bundle size',
            'Lazy queries',
            'Memory profiling',
            'Response timing',
        ],
        'accessibility' => [
            'Semantic landmarks',
            'Form descriptions',
            'Live regions',
            'Keyboard navigation',
            'Focus indicators',
            'Reduced motion',
            'Color contrast',
            'Screen reader testing',
            'Error summaries',
            'Responsive tables',
        ],
    ];
    $formats = [
        'A practical guide',
        'Patterns and examples',
        'Common pitfalls',
        'A hands-on workshop',
        'Recipes for real projects',
    ];
    $levels = ['beginner', 'intermediate', 'advanced'];
    $id = 1;
    foreach ($topics as $category => $subjects) {
        foreach ($subjects as $subject) {
            foreach ($levels as $level) {
                foreach ($formats as $format) {
                    $connection->execute(
                        'INSERT INTO resources (id,title,summary,category,level,minutes) VALUES (?,?,?,?,?,?)',
                        [
                            $id,
                            $subject . ': ' . lcfirst($format),
                            $format
                                . ' covering '
                                . strtolower($subject)
                                . ' for '
                                . $level
                                . ' developers. Explore concrete examples and techniques for your next project.',
                            $category,
                            $level,
                            4 + ($id % 22),
                        ],
                    );
                    $id++;
                }
            }
        }
    }
});
