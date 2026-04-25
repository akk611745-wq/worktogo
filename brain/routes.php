<?php

/**
 * BrainCore v3 — Route Definitions
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rawurldecode($uri);

$basePrefix = '/brain';
if (strpos($uri, $basePrefix) === 0) {
    $uri = substr($uri, strlen($basePrefix));
}

$uri = rtrim($uri, '/');
if ($uri === '' || $uri[0] !== '/') {
    $uri = '/' . $uri;
}

$routes = [
    // ── Public ──────────────────────────────────────────────
    ['GET',  '/api/decision',  'DecisionController', 'decide'],
    ['POST', '/api/decision',  'DecisionController', 'decidePost'],  // Heart integration: JSON body
    ['POST', '/api/event',     'EventController',    'store'],
    ['POST', '/api/feedback',  'FeedbackController', 'record'],

    // ── Health (no admin key — useful for uptime monitors) ──
    ['GET',  '/api/health',    'AdminController',    'health'],

    // ── Admin: Rules ─────────────────────────────────────────
    ['GET',    '/admin/rules', 'AdminController', 'listRules'],
    ['POST',   '/admin/rules', 'AdminController', 'createRule'],
    ['PUT',    '/admin/rules', 'AdminController', 'updateRule'],
    ['DELETE', '/admin/rules', 'AdminController', 'deleteRule'],

    // ── Admin: Logs + Data ───────────────────────────────────
    ['GET', '/admin/logs',                  'AdminController', 'viewLogs'],
    ['GET', '/admin/improvements',          'AdminController', 'viewImprovements'],
    ['GET', '/api/admin/improvements',      'AdminController', 'viewImprovements'],
    ['GET', '/api/admin/suggestions',       'AdminController', 'viewSuggestions'],
    ['POST','/api/admin/suggestions/approve','AdminController','approveSuggestion'],
    ['POST','/api/admin/suggestions/run',   'AdminController', 'runSuggestionEngine'],
    ['GET', '/api/admin/actions',           'AdminController', 'viewActions'],
    ['GET', '/api/admin/alerts',            'AdminController', 'viewAlerts'],

    // ── Admin: Habits (new v3) ───────────────────────────────
    ['GET', '/api/admin/habits',            'AdminController', 'viewHabits'],

    // ── Admin: Dashboard ─────────────────────────────────────
    ['GET', '/api/admin/dashboard',         'DashboardController', 'index'],
];

Router::dispatch($routes, $method, $uri);
