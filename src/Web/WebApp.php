<?php

namespace MailSupportAssistant\Web;

use MailSupportAssistant\Auth\SimpleSessionAuth;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Env;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\ProjectPaths;
use MailSupportAssistant\Tools\ToolsApiClient;
use Throwable;

class WebApp
{
    private SimpleSessionAuth $auth;
    private Logger $logger;
    private ToolsApiClient $tools;
    private MailAssistantRunner $runner;

    public function __construct(SimpleSessionAuth $auth, Logger $logger, ToolsApiClient $tools, MailAssistantRunner $runner)
    {
        $this->auth = $auth;
        $this->logger = $logger;
        $this->tools = $tools;
        $this->runner = $runner;
    }

    public function handle(): void
    {
        $this->auth->boot();
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($path === '/logout') {
            $this->auth->logout();
            $this->redirect('/');
        }

        if (!$this->auth->check()) {
            if ($method === 'POST') {
                $ok = $this->auth->attempt((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
                if ($ok) {
                    $this->redirect('/');
                }
                $this->render('login', ['error' => 'Invalid username or password.']);
                return;
            }

            $this->render('login', ['error' => null]);
            return;
        }

        if (($this->requestParam('ajax') !== '') || $this->wantsJson()) {
            $this->handleAjax($method);
            return;
        }

        $dashboard = $this->buildDashboardPayload();

        $this->render('dashboard', $dashboard);
    }

    private function buildDashboardPayload(): array
    {
        $config = [];
        $supportCases = [];
        $liveInboxPreview = [];
        $configError = null;
        if ($this->tools->hasToken()) {
            try {
                $config = $this->tools->fetchConfig();
                try {
                    $casesPayload = $this->tools->fetchCases(['limit' => 60]);
                    $supportCases = is_array($casesPayload['cases'] ?? null) ? (array) $casesPayload['cases'] : [];
                } catch (Throwable $e) {
                    $this->logger->warning('Could not fetch support cases from Tools for the standalone dashboard.', [
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    $liveInboxPreview = $this->buildLiveInboxPreview($config);
                } catch (Throwable $e) {
                    $this->logger->warning('Could not build live inbox preview for the standalone dashboard.', [
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (Throwable $e) {
                $configError = $e->getMessage();
            }
        } else {
            $configError = 'MAIL_ASSISTANT_TOOLS_TOKEN is not configured yet.';
        }

        $lastRun = $this->logger->lastRun();
        $messageState = $this->runner->messageStateSummary();
        $logLines = $this->logger->tail(25);
        $messageCopies = $this->loadRecentMessageCopies(30);

        return [
            'title' => (string) Env::get('MAIL_ASSISTANT_TITLE', 'Mail Support Assistant'),
            'toolsBaseUrl' => $this->tools->getBaseUrl(),
            'toolsAdminUrl' => (string) Env::get('MAIL_ASSISTANT_TOOLS_ADMIN_URL', ''),
            'config' => $config,
            'configError' => $configError,
            'supportCases' => $supportCases,
            'liveInboxPreview' => $liveInboxPreview,
            'lastRun' => $lastRun,
            'messageState' => $messageState,
            'logLines' => $logLines,
            'messageCopies' => $messageCopies,
            'ui' => [
                'alerts' => $this->buildAlertSummary($config, $lastRun),
                'overview' => $this->buildOverviewSummary($config, $configError, $lastRun, $messageState, $messageCopies, $liveInboxPreview, $supportCases),
                'activity' => $this->buildLastRunMailboxCards($lastRun, $messageCopies, $config, $supportCases, $liveInboxPreview),
                'history' => $this->buildHistoryMailboxCards($messageState),
                'config' => $this->buildConfigSummary($config, $configError),
                'logs' => $this->buildLogSummary($logLines),
            ],
            'imapAvailable' => function_exists('imap_open'),
            'projectRoot' => ProjectPaths::root(),
            'ajaxBase' => $this->requestBasePath(),
        ];
    }

    private function buildOverviewSummary(array $config, ?string $configError, array $lastRun, array $messageState, array $messageCopies, array $liveInboxPreview = [], array $supportCases = []): array
    {
        $mailboxes = array_values((array) ($config['mailboxes'] ?? []));
        $ruleCount = 0;
        $noMatchRuleCount = 0;
        $liveUnread = 0;
        foreach ($mailboxes as $mailbox) {
            $ruleCount += count((array) ($mailbox['rules'] ?? []));
            $noMatchRuleCount += count((array) (($mailbox['defaults']['generic_no_match_rules'] ?? null) ?: []));
        }
        foreach ((array) $liveInboxPreview as $previewMailbox) {
            if (!is_array($previewMailbox)) {
                continue;
            }
            $liveUnread += count((array) ($previewMailbox['messages'] ?? []));
        }

        return [
            [
                'label' => 'Configured mailboxes',
                'value' => count($mailboxes),
                'note' => $configError ? 'Config fetch failed.' : 'Fetched from Tools config.',
                'tone' => $configError ? 'danger' : 'primary',
            ],
            [
                'label' => 'Reply rules',
                'value' => $ruleCount,
                'note' => 'Explicit matched-rule automation rows.',
                'tone' => 'info',
            ],
            [
                'label' => 'No-match fallback rows',
                'value' => $noMatchRuleCount,
                'note' => 'Ordered unmatched fallback paths from Tools.',
                'tone' => 'info',
            ],
            [
                'label' => 'Last run handled',
                'value' => (int) ($lastRun['messages_handled'] ?? 0),
                'note' => 'Handled in the latest saved run.',
                'tone' => ((int) ($lastRun['messages_handled'] ?? 0)) > 0 ? 'success' : 'muted',
            ],
            [
                'label' => 'Last run skipped',
                'value' => (int) ($lastRun['messages_skipped'] ?? 0),
                'note' => 'Unread messages left untouched or deferred.',
                'tone' => ((int) ($lastRun['messages_skipped'] ?? 0)) > 0 ? 'warning' : 'muted',
            ],
            [
                'label' => 'Live unread preview',
                'value' => $liveUnread,
                'note' => 'Current unread IMAP messages visible to the dashboard right now.',
                'tone' => $liveUnread > 0 ? 'info' : 'muted',
            ],
            [
                'label' => 'Tracked Tools cases',
                'value' => count($supportCases),
                'note' => 'Threaded conversations already stored back in Tools.',
                'tone' => count($supportCases) > 0 ? 'primary' : 'muted',
            ],
            [
                'label' => 'Local history records',
                'value' => (int) ($messageState['total_records'] ?? 0),
                'note' => 'Diagnostic continuity history under storage/state.',
                'tone' => 'muted',
            ],
            [
                'label' => 'Saved message copies',
                'value' => count($messageCopies),
                'note' => 'Recent local cached copies with optional headers/body preview.',
                'tone' => 'muted',
            ],
            [
                'label' => 'Quota alerts',
                'value' => count((array) ($lastRun['quota_alerts'] ?? [])),
                'note' => 'Latest run quota/billing warnings that need operator attention.',
                'tone' => count((array) ($lastRun['quota_alerts'] ?? [])) > 0 ? 'danger' : 'muted',
            ],
        ];
    }

    private function buildAlertSummary(array $config, array $lastRun): array
    {
        $alerts = [];
        $budget = is_array($config['user']['ai_daily_budget'] ?? null) ? $config['user']['ai_daily_budget'] : [];
        if ($budget) {
            $isUnlimited = !empty($budget['is_unlimited']);
            $remaining = $budget['remaining'] ?? null;
            $cap = $budget['cap'] ?? null;
            if (!$isUnlimited && $remaining !== null) {
                $remaining = (int) $remaining;
                $cap = $cap !== null ? (int) $cap : null;
                if ($remaining <= 0) {
                    $alerts[] = [
                        'severity' => 'danger',
                        'title' => 'Daily AI budget exhausted',
                        'message' => 'The Tools-side daily AI budget for this token owner is at zero remaining. New AI-powered replies or unmatched AI triage may stop until the budget resets or is raised.',
                        'source' => 'tools_ai_daily_budget',
                    ];
                } elseif ($cap !== null && $cap > 0 && $remaining <= max(250, (int) floor($cap * 0.1))) {
                    $alerts[] = [
                        'severity' => 'warning',
                        'title' => 'Daily AI budget running low',
                        'message' => 'Only ' . $remaining . ' daily AI units remain for this token owner, so AI-enabled mail flows may stop later today.',
                        'source' => 'tools_ai_daily_budget',
                    ];
                }
            }
        }

        foreach ((array) ($lastRun['alerts'] ?? []) as $alert) {
            if (!is_array($alert)) {
                continue;
            }

            $alerts[] = [
                'severity' => (string) (($alert['severity'] ?? null) ?: 'warning'),
                'title' => (string) (($alert['type'] ?? null) === 'ai_quota'
                    ? 'AI quota / billing failure detected'
                    : (($alert['title'] ?? null) ?: 'Runtime alert')),
                'message' => trim(implode(' ', array_filter([
                    (string) (($alert['mailbox'] ?? null) ? ('Mailbox: ' . $alert['mailbox'] . '.') : ''),
                    (string) (($alert['subject'] ?? null) ? ('Subject: ' . $alert['subject'] . '.') : ''),
                    (string) (($alert['error'] ?? null) ?: ''),
                ]))),
                'source' => (string) (($alert['type'] ?? null) ?: 'runtime_alert'),
                'meta' => $alert,
            ];
        }

        return $alerts;
    }

    private function buildLastRunMailboxCards(array $lastRun, array $messageCopies, array $config = [], array $supportCases = [], array $liveInboxPreview = []): array
    {
        $copyIndex = $this->indexMessageCopies($messageCopies);
        $caseIndex = $this->indexSupportCases($supportCases);
        $cards = [];
        $configuredMailboxes = [];
        $livePreviewByMailbox = [];
        foreach ((array) ($config['mailboxes'] ?? []) as $mailbox) {
            if (!is_array($mailbox)) {
                continue;
            }

            $configuredMailboxes[(int) ($mailbox['id'] ?? 0)] = $mailbox;
        }
        foreach ((array) $liveInboxPreview as $previewMailbox) {
            if (!is_array($previewMailbox)) {
                continue;
            }
            $livePreviewByMailbox[(int) ($previewMailbox['mailbox_id'] ?? 0)] = $previewMailbox;
        }

        foreach ((array) ($lastRun['mailboxes'] ?? []) as $mailbox) {
            if (!is_array($mailbox)) {
                continue;
            }

            $mailboxId = (int) ($mailbox['id'] ?? 0);
            $messages = [];
            foreach ((array) ($mailbox['message_results'] ?? []) as $message) {
                if (!is_array($message)) {
                    continue;
                }

                $copy = $this->findMessageCopyForResult($message, $copyIndex);
                $messages[] = [
                    'uid' => (int) ($message['uid'] ?? 0),
                    'message_id' => (string) ($message['message_id'] ?? ''),
                    'message_key' => (string) ($message['message_key'] ?? ''),
                    'thread_key' => (string) ($message['thread_key'] ?? ''),
                    'in_reply_to' => (string) ($message['in_reply_to'] ?? ''),
                    'references' => array_values((array) ($message['references'] ?? [])),
                    'subject' => (string) ($message['subject'] ?? ''),
                    'subject_normalized' => (string) ($message['subject_normalized'] ?? ''),
                    'from' => (string) ($message['from'] ?? ''),
                    'to' => (string) ($message['to'] ?? ''),
                    'date' => (string) ($message['date'] ?? ''),
                    'outcome' => (string) ($message['outcome'] ?? ''),
                    'reason' => (string) ($message['reason'] ?? ''),
                    'reason_label' => $this->humanizeIdentifier((string) ($message['reason'] ?? '')),
                    'body_excerpt' => (string) (($message['body_excerpt'] ?? null) ?: ($copy['body_excerpt'] ?? '')),
                    'selected_rule' => is_array($message['selected_rule'] ?? null) ? $message['selected_rule'] : null,
                    'matching_rule_count' => (int) ($message['matching_rule_count'] ?? 0),
                    'matching_rules' => array_values((array) ($message['matching_rules'] ?? [])),
                    'generic_ai_decision' => is_array($message['generic_ai_decision'] ?? null) ? $message['generic_ai_decision'] : [],
                    'reply_message_id' => (string) ($message['reply_message_id'] ?? ''),
                    'reply_issue_id' => (string) ($message['reply_issue_id'] ?? ''),
                    'reply_transport' => (string) ($message['reply_transport'] ?? ''),
                    'rule_resolution_source' => (string) ($message['rule_resolution_source'] ?? ''),
                    'reused_from_message_id' => (string) ($message['reused_from_message_id'] ?? ''),
                    'support_case' => $this->findSupportCaseForMessage($caseIndex, $mailboxId ?? 0, $message),
                    'copy' => $copy,
                ];
            }

            $configuredMailbox = $configuredMailboxes[$mailboxId] ?? [];
            if (!count($messages) && isset($livePreviewByMailbox[$mailboxId])) {
                $messages = $this->attachSupportCasesToMessages((array) ($livePreviewByMailbox[$mailboxId]['messages'] ?? []), $caseIndex, $mailboxId);
            }

            $cards[] = [
                'id' => $mailboxId,
                'name' => (string) ($mailbox['name'] ?? (($configuredMailbox['name'] ?? null) ?: '')),
                'scanned' => (int) ($mailbox['scanned'] ?? 0),
                'handled' => (int) ($mailbox['handled'] ?? 0),
                'skipped' => (int) ($mailbox['skipped'] ?? 0),
                'failed' => (int) ($mailbox['failed'] ?? 0),
                'assistant_sent_skipped' => (int) ($mailbox['assistant_sent_skipped'] ?? 0),
                'read_skipped' => (int) ($mailbox['read_skipped'] ?? 0),
                'imap' => [
                    'host' => (string) (($configuredMailbox['imap']['host'] ?? null) ?: ''),
                    'port' => (int) (($configuredMailbox['imap']['port'] ?? null) ?: 0),
                    'folder' => (string) (($configuredMailbox['imap']['folder'] ?? null) ?: 'INBOX'),
                    'encryption' => (string) (($configuredMailbox['imap']['encryption'] ?? null) ?: ''),
                ],
                'available_rules' => array_values(array_map(function (array $rule): array {
                    return [
                        'id' => (int) ($rule['id'] ?? 0),
                        'name' => (string) ($rule['name'] ?? ''),
                        'sort_order' => (int) ($rule['sort_order'] ?? 0),
                    ];
                }, array_values(array_filter((array) ($configuredMailbox['rules'] ?? []), static function ($rule): bool {
                    return is_array($rule) && (int) ($rule['id'] ?? 0) > 0;
                })))),
                'source' => count($messages) ? ((isset($livePreviewByMailbox[$mailboxId]) && !count((array) ($mailbox['message_results'] ?? []))) ? 'live_inbox' : 'last_run') : 'last_run',
                'messages' => $messages,
                'errors' => array_values((array) ($mailbox['errors'] ?? [])),
            ];
        }

        foreach ($configuredMailboxes as $mailboxId => $configuredMailbox) {
            $alreadyPresent = false;
            foreach ($cards as $card) {
                if ((int) ($card['id'] ?? 0) === (int) $mailboxId) {
                    $alreadyPresent = true;
                    break;
                }
            }

            if ($alreadyPresent) {
                continue;
            }

            $previewMessages = $this->attachSupportCasesToMessages((array) (($livePreviewByMailbox[(int) $mailboxId]['messages'] ?? null) ?: []), $caseIndex, (int) $mailboxId);

            $cards[] = [
                'id' => (int) $mailboxId,
                'name' => (string) (($configuredMailbox['name'] ?? null) ?: 'Mailbox'),
                'scanned' => 0,
                'handled' => 0,
                'skipped' => 0,
                'failed' => 0,
                'assistant_sent_skipped' => 0,
                'read_skipped' => 0,
                'imap' => [
                    'host' => (string) (($configuredMailbox['imap']['host'] ?? null) ?: ''),
                    'port' => (int) (($configuredMailbox['imap']['port'] ?? null) ?: 0),
                    'folder' => (string) (($configuredMailbox['imap']['folder'] ?? null) ?: 'INBOX'),
                    'encryption' => (string) (($configuredMailbox['imap']['encryption'] ?? null) ?: ''),
                ],
                'available_rules' => array_values(array_map(function (array $rule): array {
                    return [
                        'id' => (int) ($rule['id'] ?? 0),
                        'name' => (string) ($rule['name'] ?? ''),
                        'sort_order' => (int) ($rule['sort_order'] ?? 0),
                    ];
                }, array_values(array_filter((array) ($configuredMailbox['rules'] ?? []), static function ($rule): bool {
                    return is_array($rule) && (int) ($rule['id'] ?? 0) > 0;
                })))),
                'source' => count($previewMessages) ? 'live_inbox' : 'config_only',
                'messages' => $previewMessages,
                'errors' => [],
            ];
        }

        return $cards;
    }

    private function buildHistoryMailboxCards(array $messageState): array
    {
        $cards = [];
        foreach ((array) ($messageState['mailboxes'] ?? []) as $mailboxId => $mailbox) {
            if (!is_array($mailbox)) {
                continue;
            }

            $cards[] = [
                'id' => (string) $mailboxId,
                'count' => (int) ($mailbox['count'] ?? 0),
                'count_pending' => (int) ($mailbox['count_pending'] ?? 0),
                'count_already_replied' => (int) ($mailbox['count_already_replied'] ?? 0),
                'status_counts' => (array) ($mailbox['status_counts'] ?? []),
                'recent' => array_values((array) ($mailbox['recent'] ?? [])),
                'recent_all' => array_values((array) ($mailbox['recent_all'] ?? [])),
            ];
        }

        return $cards;
    }

    private function buildConfigSummary(array $config, ?string $configError = null): array
    {
        $mailboxes = [];
        foreach ((array) ($config['mailboxes'] ?? []) as $mailbox) {
            if (!is_array($mailbox)) {
                continue;
            }

            $defaults = (array) ($mailbox['defaults'] ?? []);
            $rules = array_values((array) ($mailbox['rules'] ?? []));
            $noMatchRules = array_values((array) (($defaults['generic_no_match_rules'] ?? null) ?: []));

            $mailboxes[] = [
                'id' => (int) ($mailbox['id'] ?? 0),
                'name' => (string) ($mailbox['name'] ?? ''),
                'imap' => [
                    'host' => (string) (($mailbox['imap']['host'] ?? null) ?: ''),
                    'port' => (int) (($mailbox['imap']['port'] ?? null) ?: 0),
                    'folder' => (string) (($mailbox['imap']['folder'] ?? null) ?: 'INBOX'),
                    'encryption' => (string) (($mailbox['imap']['encryption'] ?? null) ?: ''),
                ],
                'defaults' => [
                    'from_name' => (string) ($defaults['from_name'] ?? ''),
                    'from_email' => (string) ($defaults['from_email'] ?? ''),
                    'bcc' => (string) ($defaults['bcc'] ?? ''),
                    'run_limit' => (int) ($defaults['run_limit'] ?? 0),
                    'footer' => (string) ($defaults['footer'] ?? ''),
                    'mark_seen_on_skip' => !empty($defaults['mark_seen_on_skip']),
                    'generic_no_match_ai_enabled' => !empty($defaults['generic_no_match_ai_enabled']),
                    'generic_no_match_ai_model' => (string) ($defaults['generic_no_match_ai_model'] ?? ''),
                    'generic_no_match_ai_reasoning_effort' => (string) ($defaults['generic_no_match_ai_reasoning_effort'] ?? ''),
                    'generic_no_match_if' => (string) ($defaults['generic_no_match_if'] ?? ''),
                    'generic_no_match_instruction' => (string) ($defaults['generic_no_match_instruction'] ?? ''),
                    'generic_no_match_footer' => (string) ($defaults['generic_no_match_footer'] ?? ''),
                    'spam_score_reply_threshold' => $defaults['spam_score_reply_threshold'] ?? null,
                    'subject_trim_prefixes' => array_values((array) ($defaults['subject_trim_prefixes'] ?? [])),
                ],
                'notes' => (string) ($mailbox['notes'] ?? ''),
                'rule_count' => count($rules),
                'no_match_rule_count' => count($noMatchRules),
                'rules' => array_map(function (array $rule): array {
                    return [
                        'id' => (int) ($rule['id'] ?? 0),
                        'name' => (string) ($rule['name'] ?? ''),
                        'sort_order' => (int) ($rule['sort_order'] ?? 0),
                        'match' => (array) ($rule['match'] ?? []),
                        'reply_enabled' => !empty($rule['reply']['enabled']),
                        'ai_enabled' => !empty($rule['reply']['ai_enabled']),
                        'reply' => [
                            'subject_prefix' => (string) (($rule['reply']['subject_prefix'] ?? null) ?: ''),
                            'from_name' => (string) (($rule['reply']['from_name'] ?? null) ?: ''),
                            'from_email' => (string) (($rule['reply']['from_email'] ?? null) ?: ''),
                            'bcc' => (string) (($rule['reply']['bcc'] ?? null) ?: ''),
                            'template_text' => (string) (($rule['reply']['template_text'] ?? null) ?: ''),
                            'footer_mode' => (string) (($rule['reply']['footer_mode'] ?? null) ?: ''),
                            'footer_text' => (string) (($rule['reply']['footer_text'] ?? null) ?: ''),
                            'responder_name' => (string) (($rule['reply']['responder_name'] ?? null) ?: ''),
                            'persona_profile' => (string) (($rule['reply']['persona_profile'] ?? null) ?: ''),
                            'mood' => (string) (($rule['reply']['mood'] ?? null) ?: ''),
                            'custom_instruction' => (string) (($rule['reply']['custom_instruction'] ?? null) ?: ''),
                            'ai_model' => (string) (($rule['reply']['ai_model'] ?? null) ?: ''),
                            'ai_reasoning_effort' => (string) (($rule['reply']['ai_reasoning_effort'] ?? null) ?: ''),
                        ],
                        'subject_trim_prefixes' => array_values((array) ($rule['subject_trim_prefixes'] ?? [])),
                        'post_handle' => (array) ($rule['post_handle'] ?? []),
                        'fallback_rule' => (array) ($rule['fallback_rule'] ?? []),
                    ];
                }, $rules),
                'no_match_rules' => array_map(function (array $row): array {
                    return [
                        'id' => (int) ($row['id'] ?? 0),
                        'sort_order' => (int) ($row['sort_order'] ?? 0),
                        'if' => (string) (($row['if'] ?? null) ?: ($row['if_condition'] ?? null) ?: ''),
                        'instruction' => (string) ($row['instruction'] ?? ''),
                        'footer' => (string) ($row['footer'] ?? ''),
                        'ai_model' => (string) ($row['ai_model'] ?? ''),
                        'ai_reasoning_effort' => (string) ($row['ai_reasoning_effort'] ?? ''),
                        'is_active' => !array_key_exists('is_active', $row) || !empty($row['is_active']),
                    ];
                }, $noMatchRules),
            ];
        }

        return [
            'error' => $configError,
            'empty_reason' => $configError ?: (empty($mailboxes) ? 'No active Tools mailboxes are visible for this token owner yet.' : ''),
            'user' => is_array($config['user'] ?? null) ? $config['user'] : [],
            'token' => is_array($config['token'] ?? null) ? $config['token'] : [],
            'mailboxes' => $mailboxes,
        ];
    }

    private function buildLogSummary(array $logLines): array
    {
        $entries = [];
        foreach ($logLines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $entries[] = [
                'line' => $line,
                'level' => $this->extractLogLevel($line),
            ];
        }

        return $entries;
    }

    private function extractLogLevel(string $line): string
    {
        if (preg_match('/]\s+([A-Z]+)\s+/u', $line, $matches) === 1) {
            return strtolower((string) ($matches[1] ?? 'info'));
        }

        return 'info';
    }

    private function humanizeIdentifier(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace(['_', '-'], ' ', $value);

        return ucfirst($value);
    }

    private function loadRecentMessageCopies(int $limit = 20): array
    {
        $dir = ProjectPaths::messageCopies();
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        usort($files, static function (string $left, string $right): int {
            return filemtime($right) <=> filemtime($left);
        });

        $copies = [];
        foreach (array_slice($files, 0, max(1, $limit)) as $file) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }

            $message = is_array($decoded['message'] ?? null) ? $decoded['message'] : [];
            $headers = is_array($message['headers_map'] ?? null) ? $message['headers_map'] : [];
            $copies[] = [
                'path' => $file,
                'filename' => basename($file),
                'saved_at' => (string) ($decoded['saved_at'] ?? ''),
                'reason' => (string) ($decoded['reason'] ?? ''),
                'uid' => (int) ($message['uid'] ?? 0),
                'message_id' => (string) ($message['message_id'] ?? ''),
                'message_key' => (string) ($message['message_key'] ?? ''),
                'subject' => (string) ($message['subject'] ?? ''),
                'from' => (string) ($message['from'] ?? ''),
                'to' => (string) ($message['to'] ?? ''),
                'date' => (string) ($message['date'] ?? ''),
                'headers_map' => $headers,
                'body_excerpt' => $this->excerpt((string) (($message['body_text_reply_aware'] ?? null) ?: ($message['body_text'] ?? '')), 900),
            ];
        }

        return $copies;
    }

    private function indexMessageCopies(array $copies): array
    {
        $index = [
            'message_key' => [],
            'message_id' => [],
            'uid' => [],
        ];

        foreach ($copies as $copy) {
            if (!is_array($copy)) {
                continue;
            }

            $messageKey = strtolower(trim((string) ($copy['message_key'] ?? '')));
            $messageId = strtolower(trim((string) ($copy['message_id'] ?? '')));
            $uid = (int) ($copy['uid'] ?? 0);

            if ($messageKey !== '' && !isset($index['message_key'][$messageKey])) {
                $index['message_key'][$messageKey] = $copy;
            }
            if ($messageId !== '' && !isset($index['message_id'][$messageId])) {
                $index['message_id'][$messageId] = $copy;
            }
            if ($uid > 0 && !isset($index['uid'][(string) $uid])) {
                $index['uid'][(string) $uid] = $copy;
            }
        }

        return $index;
    }

    private function findMessageCopyForResult(array $message, array $copyIndex): ?array
    {
        $messageKey = strtolower(trim((string) ($message['message_key'] ?? '')));
        if ($messageKey !== '' && isset($copyIndex['message_key'][$messageKey])) {
            return $copyIndex['message_key'][$messageKey];
        }

        $messageId = strtolower(trim((string) ($message['message_id'] ?? '')));
        if ($messageId !== '' && isset($copyIndex['message_id'][$messageId])) {
            return $copyIndex['message_id'][$messageId];
        }

        $uid = (int) ($message['uid'] ?? 0);
        if ($uid > 0 && isset($copyIndex['uid'][(string) $uid])) {
            return $copyIndex['uid'][(string) $uid];
        }

        return null;
    }

    private function excerpt(string $text, int $maxLength = 300): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > $maxLength) {
                return rtrim(mb_substr($text, 0, $maxLength, 'UTF-8')) . '…';
            }
        } elseif (strlen($text) > $maxLength) {
            return rtrim(substr($text, 0, $maxLength)) . '...';
        }

        return $text;
    }

    private function buildLiveInboxPreview(array $config): array
    {
        $previews = [];

        foreach ((array) ($config['mailboxes'] ?? []) as $mailbox) {
            if (!is_array($mailbox)) {
                continue;
            }

            $mailboxId = (int) ($mailbox['id'] ?? 0);
            if ($mailboxId < 1) {
                continue;
            }

            try {
                $messages = $this->runner->previewMailboxMessages($mailbox, 10);
                $previews[] = [
                    'mailbox_id' => $mailboxId,
                    'mailbox_name' => (string) ($mailbox['name'] ?? ''),
                    'messages' => $messages,
                ];
            } catch (Throwable $e) {
                $previews[] = [
                    'mailbox_id' => $mailboxId,
                    'mailbox_name' => (string) ($mailbox['name'] ?? ''),
                    'messages' => [],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $previews;
    }

    private function indexSupportCases(array $supportCases): array
    {
        $index = [];

        foreach ($supportCases as $case) {
            if (!is_array($case)) {
                continue;
            }

            $mailboxId = (int) ($case['mailbox_id'] ?? 0);
            if ($mailboxId < 1) {
                continue;
            }

            $index[$mailboxId][] = $case;
        }

        return $index;
    }

    private function attachSupportCasesToMessages(array $messages, array $caseIndex, int $mailboxId): array
    {
        $mapped = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $message['support_case'] = $this->findSupportCaseForMessage($caseIndex, $mailboxId, $message);
            $mapped[] = $message;
        }

        return $mapped;
    }

    private function findSupportCaseForMessage(array $caseIndex, int $mailboxId, array $message): ?array
    {
        foreach ((array) ($caseIndex[$mailboxId] ?? []) as $case) {
            if (!is_array($case)) {
                continue;
            }

            $replyIssueId = strtoupper(trim((string) ($message['reply_issue_id'] ?? '')));
            if ($replyIssueId !== '' && strcasecmp((string) ($case['reply_issue_id'] ?? ''), $replyIssueId) === 0) {
                return $case;
            }

            $threadKey = trim((string) ($message['thread_key'] ?? ''));
            if ($threadKey !== '' && strcasecmp((string) ($case['thread_key'] ?? ''), $threadKey) === 0) {
                return $case;
            }

            foreach ((array) ($case['recent_messages'] ?? []) as $caseMessage) {
                if (!is_array($caseMessage)) {
                    continue;
                }

                if (
                    !empty($message['message_id'])
                    && strcasecmp((string) ($caseMessage['message_id'] ?? ''), (string) $message['message_id']) === 0
                ) {
                    return $case;
                }

                if (
                    !empty($message['message_key'])
                    && strcasecmp((string) ($caseMessage['message_key'] ?? ''), (string) $message['message_key']) === 0
                ) {
                    return $case;
                }
            }
        }

        return null;
    }

    private function resolveActiveConfig(): array
    {
        if (!$this->tools->hasToken()) {
            throw new \RuntimeException('MAIL_ASSISTANT_TOOLS_TOKEN is not configured yet.');
        }

        return $this->tools->fetchConfig();
    }

    private function findConfiguredMailbox(array $config, int $mailboxId): array
    {
        foreach ((array) ($config['mailboxes'] ?? []) as $mailbox) {
            if (is_array($mailbox) && (int) ($mailbox['id'] ?? 0) === $mailboxId) {
                return $mailbox;
            }
        }

        throw new \RuntimeException('Mailbox not found in the current Tools config payload.');
    }

    private function findConfiguredRule(array $mailbox, int $ruleId): ?array
    {
        if ($ruleId < 1) {
            return null;
        }

        foreach ((array) ($mailbox['rules'] ?? []) as $rule) {
            if (is_array($rule) && (int) ($rule['id'] ?? 0) === $ruleId) {
                return $rule;
            }
        }

        throw new \RuntimeException('Selected rule no longer exists in the current Tools config payload.');
    }

    private function resolveOperatorMessageContext(int $mailboxId, int $uid, string $messageId, string $messageKey = ''): array
    {
        $lastRun = $this->logger->lastRun();
        $copyIndex = $this->indexMessageCopies($this->loadRecentMessageCopies(60));

        foreach ((array) ($lastRun['mailboxes'] ?? []) as $mailbox) {
            if (!is_array($mailbox) || (int) ($mailbox['id'] ?? 0) !== $mailboxId) {
                continue;
            }

            foreach ((array) ($mailbox['message_results'] ?? []) as $message) {
                if (!is_array($message)) {
                    continue;
                }

                $matches = ($uid > 0 && (int) ($message['uid'] ?? 0) === $uid)
                    || ($messageId !== '' && strcasecmp((string) ($message['message_id'] ?? ''), $messageId) === 0)
                    || ($messageKey !== '' && strcasecmp((string) ($message['message_key'] ?? ''), $messageKey) === 0);
                if (!$matches) {
                    continue;
                }

                $copy = $this->findMessageCopyForResult($message, $copyIndex);
                $copyPayload = $this->loadFullMessageCopy($copy);
                $copyMessage = is_array($copyPayload['message'] ?? null) ? $copyPayload['message'] : [];

                return [
                    'uid' => (int) ($message['uid'] ?? 0),
                    'message_id' => (string) ($message['message_id'] ?? ''),
                    'message_key' => (string) ($message['message_key'] ?? ''),
                    'reply_issue_id' => (string) ($message['reply_issue_id'] ?? ''),
                    'thread_key' => (string) ($message['thread_key'] ?? ''),
                    'in_reply_to' => (string) ($message['in_reply_to'] ?? ''),
                    'references' => array_values((array) ($message['references'] ?? [])),
                    'subject' => (string) ($message['subject'] ?? ''),
                    'subject_normalized' => (string) (($message['subject_normalized'] ?? null) ?: ''),
                    'from' => (string) ($message['from'] ?? ''),
                    'to' => (string) ($message['to'] ?? ''),
                    'date' => (string) ($message['date'] ?? ''),
                    'body_text' => (string) (($copyMessage['body_text'] ?? null) ?: (($message['body_excerpt'] ?? null) ?: '')),
                    'body_text_reply_aware' => (string) (($copyMessage['body_text_reply_aware'] ?? null) ?: (($copyMessage['body_text'] ?? null) ?: (($message['body_excerpt'] ?? null) ?: ''))),
                    'body_text_raw' => (string) (($copyMessage['body_text_raw'] ?? null) ?: ''),
                    'headers_map' => is_array($copyMessage['headers_map'] ?? null) ? $copyMessage['headers_map'] : [],
                    'headers_raw' => (string) (($copyMessage['headers_raw'] ?? null) ?: ''),
                    'copy' => $copy,
                ];
            }
        }

        $config = $this->resolveActiveConfig();
        $mailbox = $this->findConfiguredMailbox($config, $mailboxId);

        return $this->runner->loadOperatorMessageFromLiveInbox($mailbox, $uid, $messageId, $messageKey);
    }

    private function loadFullMessageCopy(?array $copy): array
    {
        $path = is_array($copy) ? (string) ($copy['path'] ?? '') : '';
        if ($path === '' || !is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function persistOperatorActionIntoLastRun(int $mailboxId, array $messageIdentity, array $actionResult): void
    {
        $lastRun = $this->logger->lastRun();
        if (!$lastRun) {
            return;
        }

        foreach ((array) ($lastRun['mailboxes'] ?? []) as $mailboxIndex => $mailbox) {
            if (!is_array($mailbox) || (int) ($mailbox['id'] ?? 0) !== $mailboxId) {
                continue;
            }

            foreach ((array) ($mailbox['message_results'] ?? []) as $messageIndex => $message) {
                if (!is_array($message)) {
                    continue;
                }

                $matches = ((int) ($message['uid'] ?? 0) > 0 && (int) ($message['uid'] ?? 0) === (int) ($messageIdentity['uid'] ?? 0))
                    || ((string) ($message['message_id'] ?? '') !== '' && strcasecmp((string) ($message['message_id'] ?? ''), (string) ($messageIdentity['message_id'] ?? '')) === 0)
                    || ((string) ($message['message_key'] ?? '') !== '' && strcasecmp((string) ($message['message_key'] ?? ''), (string) ($messageIdentity['message_key'] ?? '')) === 0);
                if (!$matches) {
                    continue;
                }

                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['outcome'] = !empty($actionResult['post_handle_warning']) ? 'warning' : 'handled';
                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['reason'] = (string) ($actionResult['reason'] ?? 'manual_operator_action');
                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['selected_rule'] = $actionResult['selected_rule'] ?? null;
                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['matching_rule_count'] = !empty($actionResult['selected_rule']) ? 1 : 0;
                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['matching_rules'] = !empty($actionResult['selected_rule']) ? [$actionResult['selected_rule']] : [];
                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['reply_message_id'] = (string) ($actionResult['reply_message_id'] ?? '');
                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['reply_issue_id'] = (string) ($actionResult['reply_issue_id'] ?? '');
                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['reply_transport'] = (string) ($actionResult['reply_transport'] ?? '');
                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['post_handle_action'] = (string) ($actionResult['post_handle_action'] ?? '');
                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['post_handle_warning'] = (string) ($actionResult['post_handle_warning'] ?? '');
                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['rule_resolution_source'] = !empty($actionResult['selected_rule'])
                    ? 'manual_operator_rule_assignment'
                    : 'manual_operator_action';
                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['operator_action'] = (string) ($actionResult['action'] ?? 'manual_operator_action');
                $lastRun['mailboxes'][$mailboxIndex]['message_results'][$messageIndex]['operator_action_at'] = date('c');
                $this->recalculateLastRunCounters($lastRun);
                $this->logger->saveLastRun($lastRun);

                return;
            }
        }
    }

    private function recalculateLastRunCounters(array &$lastRun): void
    {
        $lastRun['messages_scanned'] = 0;
        $lastRun['messages_handled'] = 0;
        $lastRun['messages_skipped'] = 0;
        $lastRun['messages_failed'] = 0;

        foreach ((array) ($lastRun['mailboxes'] ?? []) as $mailboxIndex => $mailbox) {
            if (!is_array($mailbox)) {
                continue;
            }

            $handled = 0;
            $skipped = 0;
            $failed = 0;
            $scanned = count((array) ($mailbox['message_results'] ?? []));
            foreach ((array) ($mailbox['message_results'] ?? []) as $message) {
                $outcome = strtolower(trim((string) ($message['outcome'] ?? '')));
                if (in_array($outcome, ['handled', 'warning'], true)) {
                    $handled++;
                } elseif ($outcome === 'error') {
                    $failed++;
                } else {
                    $skipped++;
                }
            }

            $lastRun['mailboxes'][$mailboxIndex]['scanned'] = $scanned;
            $lastRun['mailboxes'][$mailboxIndex]['handled'] = $handled;
            $lastRun['mailboxes'][$mailboxIndex]['skipped'] = $skipped;
            $lastRun['mailboxes'][$mailboxIndex]['failed'] = $failed;
            $lastRun['messages_scanned'] += $scanned;
            $lastRun['messages_handled'] += $handled;
            $lastRun['messages_skipped'] += $skipped;
            $lastRun['messages_failed'] += $failed;
        }
    }

    private function handleAjax(string $method): void
    {
        $action = strtolower($this->requestParam('ajax', 'dashboard'));
        if ($action === 'refresh') {
            $action = 'dashboard';
        }

        try {
            if ($action === 'dashboard') {
                $this->json([
                    'ok' => true,
                    'data' => $this->buildDashboardPayload(),
                ]);
            }

            if ($action === 'self-test') {
                $this->json([
                    'ok' => true,
                    'result' => $this->runner->selfTest(),
                    'data' => $this->buildDashboardPayload(),
                ]);
            }

            if ($action === 'cleanup') {
                if ($method !== 'POST') {
                    $this->json(['ok' => false, 'message' => 'POST required.'], 405);
                }

                $options = [
                    'log'       => $this->requestParam('log') !== '0',
                    'last_run'  => $this->requestParam('last_run') !== '0',
                    'state'     => $this->requestParam('state') !== '0',
                    'copies'    => $this->requestParam('copies') === '1',
                ];

                $result = $this->runner->cleanup($options);
                $this->json([
                    'ok' => true,
                    'result' => $result,
                    'data' => $this->buildDashboardPayload(),
                ]);
            }

            if ($action === 'run-dry') {
                if ($method !== 'POST') {
                    $this->json(['ok' => false, 'message' => 'POST required.'], 405);
                }

                $limit = $this->requestParam('limit');
                $mailbox = $this->requestParam('mailbox');
                $result = $this->runner->run([
                    'dry_run' => true,
                    'limit' => $limit !== '' ? (int) $limit : null,
                    'mailbox' => $mailbox !== '' ? (int) $mailbox : null,
                ]);
                $status = !empty($result['ok'])
                    ? 200
                    : ((string) ($result['reason'] ?? '') === 'runner_already_active' ? 409 : 500);

                $this->json([
                    'ok' => !empty($result['ok']),
                    'result' => $result,
                    'data' => $this->buildDashboardPayload(),
                ], $status);
            }

            if ($action === 'manual-reply') {
                if ($method !== 'POST') {
                    $this->json(['ok' => false, 'message' => 'POST required.'], 405);
                }

                $mailboxId = (int) $this->requestParam('mailbox_id');
                $uid = (int) $this->requestParam('uid');
                $messageId = $this->requestParam('message_id');
                $messageKey = $this->requestParam('message_key');
                $ruleId = (int) $this->requestParam('rule_id');
                $body = $this->requestParam('body');
                if ($mailboxId < 1 || $uid < 1 || trim($body) === '') {
                    $this->json(['ok' => false, 'message' => 'Mailbox, UID, and manual reply body are required.'], 422);
                }

                $config = $this->resolveActiveConfig();
                $mailbox = $this->findConfiguredMailbox($config, $mailboxId);
                $rule = $this->findConfiguredRule($mailbox, $ruleId);
                $message = $this->resolveOperatorMessageContext($mailboxId, $uid, $messageId, $messageKey);
                $result = $this->runner->sendManualReply($mailbox, $message, $rule, $body);
                $this->persistOperatorActionIntoLastRun($mailboxId, $message, $result);

                $this->json([
                    'ok' => true,
                    'message' => 'Manual reply sent and styled with the same reply pipeline as automatic replies.',
                    'result' => $result,
                    'data' => $this->buildDashboardPayload(),
                ]);
            }

            if ($action === 'manual-mark-handled') {
                if ($method !== 'POST') {
                    $this->json(['ok' => false, 'message' => 'POST required.'], 405);
                }

                $mailboxId = (int) $this->requestParam('mailbox_id');
                $uid = (int) $this->requestParam('uid');
                $messageId = $this->requestParam('message_id');
                $messageKey = $this->requestParam('message_key');
                $ruleId = (int) $this->requestParam('rule_id');
                if ($mailboxId < 1 || $uid < 1) {
                    $this->json(['ok' => false, 'message' => 'Mailbox and UID are required.'], 422);
                }

                $config = $this->resolveActiveConfig();
                $mailbox = $this->findConfiguredMailbox($config, $mailboxId);
                $rule = $this->findConfiguredRule($mailbox, $ruleId);
                $message = $this->resolveOperatorMessageContext($mailboxId, $uid, $messageId, $messageKey);
                $result = $this->runner->markMessageHandledManually($mailbox, $message, $rule, [
                    'note' => $this->requestParam('note'),
                ]);
                $this->persistOperatorActionIntoLastRun($mailboxId, $message, $result);

                $this->json([
                    'ok' => true,
                    'message' => 'Message marked handled/read for manual follow-up so the unread poller stops retrying it.',
                    'result' => $result,
                    'data' => $this->buildDashboardPayload(),
                ]);
            }

            $this->json(['ok' => false, 'message' => 'Unknown ajax action.'], 404);
        } catch (Throwable $e) {
            $this->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function render(string $template, array $data): void
    {
        extract($data, EXTR_SKIP);
        require ProjectPaths::root() . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $template . '.php';
    }

    private function redirect(string $location): void
    {
        header('Location: ' . $location);
        exit;
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        return strpos($accept, 'application/json') !== false;
    }

    private function requestParam(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;

        return trim((string) $value);
    }

    private function requestBasePath(): string
    {
        return (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    }
}

