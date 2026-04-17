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
        $configError = null;
        if ($this->tools->hasToken()) {
            try {
                $config = $this->tools->fetchConfig();
            } catch (Throwable $e) {
                $configError = $e->getMessage();
            }
        } else {
            $configError = 'MAIL_ASSISTANT_TOOLS_TOKEN is not configured yet.';
        }

        return [
            'title' => (string) Env::get('MAIL_ASSISTANT_TITLE', 'Mail Support Assistant'),
            'toolsBaseUrl' => $this->tools->getBaseUrl(),
            'toolsAdminUrl' => (string) Env::get('MAIL_ASSISTANT_TOOLS_ADMIN_URL', ''),
            'config' => $config,
            'configError' => $configError,
            'lastRun' => $this->logger->lastRun(),
            'messageState' => $this->runner->messageStateSummary(),
            'logLines' => $this->logger->tail(25),
            'imapAvailable' => function_exists('imap_open'),
            'projectRoot' => ProjectPaths::root(),
            'ajaxBase' => $this->requestBasePath(),
        ];
    }

    private function handleAjax(string $method): void
    {
        $action = strtolower($this->requestParam('ajax', 'dashboard'));

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

                $this->json([
                    'ok' => !empty($result['ok']),
                    'result' => $result,
                    'data' => $this->buildDashboardPayload(),
                ], !empty($result['ok']) ? 200 : 500);
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

