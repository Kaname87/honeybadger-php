<?php

namespace Honeybadger;

use Honeybadger\Backtrace;
use Honeybadger\Errors\HoneybadgerError;
use Honeybadger\Util\Arr;
use Honeybadger\Util\SemiOpenStruct;

/**
 * @package  Honeybadger
 */
class Notice extends SemiOpenStruct
{

    /**
     * @var  array  The currently processing `Notice`.
     */
    public static $current;

    /**
     * @var array
     */
    protected $attribute_methods = [
        'is_ignored',
    ];
    /**
     * @var  array  Original arguments passed to constructor.
     */
    protected $args = [];
    /**
     * @var  Exception  The exception that caused this notice, if any.
     */
    protected $exception;
    /**
     * @var  Backtrace  The backtrace from the given exception or hash.
     */
    protected $backtrace;
    /**
     * @var  string  The name of the class of error (such as `Exception`).
     */
    protected $error_class;
    /**
     * @var  string  Excerpt from source file.
     */
    protected $source_extract;
    /**
     * @var  integer  The number of lines of context to include before and after
     *                source excerpt.
     */
    protected $source_extract_radius = 2;
    /**
     * @var  string  The name of the server environment (such as `production`).
     */
    protected $environment_name;
    /**
     * @var  array  CGI variables such as `REQUEST_METHOD`.
     */
    protected $cgi_data = [];
    /**
     * @var  string  The message from the exception, or a general description of
     *               the error.
     */
    protected $error_message;
    /**
     * @var  boolean  See Config#send_request_session.
     */
    protected $send_request_session;
    /**
     * @var  array  See Config#backtrace_filters
     */
    protected $backtrace_filters = [];
    /**
     * @var  array  See Config#params_filters.
     */
    protected $params_filters = [];
    /**
     * @var  array  Parameters from the query string or request body.
     */
    protected $params = [];
    /**
     * @var  string  The component (if any) which was used in this request
     *               (usually the controller).
     */
    protected $component;
    /**
     * @var  string  The action (if any) that was called in this request.
     */
    protected $action;
    /**
     * @var  array  Session data from the request.
     */
    protected $session_data = [];
    /**
     * @var  array  Additional contextual information (custom data).
     */
    protected $context = [];
    /**
     * @var  string  The path to the project that caused the error.
     */
    protected $project_root;
    /**
     * @var  string  The URL at which the error occurred (if any).
     */
    protected $url;
    /**
     * @var  array  See Config#ignore.
     */
    protected $ignore = [];
    /**
     * @var  array  See Config#ignore_by_filters.
     */
    protected $ignore_by_filters = [];
    /**
     * @var  string  The name of the notifier library sending this notice,
     *               such as "Honeybadger Notifier".
     */
    protected $notifier_name;
    /**
     * @var  string  The version number of the notifier library sending this
     *               notice, such as "2.1.3".
     */
    protected $notifier_version;
    /**
     * @var  string  A URL for more information about the notifier library
     *               sending this notice.
     */
    protected $notifier_url;
    /**
     * @var  string  The host name where this error occurred (if any).
     */
    protected $hostname;

    /**
     * @param array $args
     */
    public function __construct(array $args = [])
    {
        // Store self to allow access in callbacks.
        self::$current = $this;

        $this->args = $args;

        $this->cgi_data         = Environment::factory(Arr::get($args, 'cgi_data'));
        $this->project_root     = Arr::get($args, 'project_root');
        $this->url              = Arr::get($args, 'url', $this->cgi_data['url']);
        $this->environment_name = Arr::get($args, 'environment_name');

        $this->notifier_name    = Arr::get($args, 'notifier_name');
        $this->notifier_version = Arr::get($args, 'notifier_version');
        $this->notifier_url     = Arr::get($args, 'notifier_url');

        $this->ignore            = Arr::get($args, 'ignore', []);
        $this->ignore_by_filters = Arr::get($args, 'ignore_by_filters', []);
        $this->backtrace_filters = Arr::get($args, 'backtrace_filters', []);
        $this->params_filters    = Arr::get($args, 'params_filters', []);

        if (isset($args['parameters'])) {
            $this->params = $args['parameters'];
        } elseif (isset($args['params'])) {
            $this->params = $args['params'];
        }

        if (isset($args['component'])) {
            $this->component = $args['component'];
        } elseif (isset($args['controller'])) {
            $this->component = $args['controller'];
        } elseif (isset($this->params['controller'])) {
            $this->component = $this->params['controller'];
        }

        if (isset($args['action'])) {
            $this->action = $args['action'];
        } elseif (isset($this->params['action'])) {
            $this->action = $this->params['action'];
        }

        $this->exception = Arr::get($args, 'exception');

        if ($this->exception instanceof \Exception) {
            $backtrace = $this->exception->getTrace();

            if (empty($backtrace)) {
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            }

            $this->error_class   = get_class($this->exception);
            $this->error_message = HoneybadgerError::text($this->exception);
        } else {
            if (isset($args['backtrace']) and is_array($args['backtrace'])) {
                $backtrace = $args['backtrace'];
            } else {
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            }

            $this->error_class   = Arr::get($args, 'error_class');
            $this->error_message = Arr::get($args, 'error_message', 'Notification');
        }

        $this->backtrace = Backtrace::parse(
            $backtrace,
            [
                'filters' => $this->backtrace_filters,
            ]
        );

        $this->hostname = gethostname();

        $this->source_extract_radius = Arr::get($args, 'source_extract_radius', 2);
        $this->source_extract        = $this->extractSourceFromBacktrace();

        $this->send_request_session = Arr::get($args, 'send_request_session', true);

        $this->findSessionData();
        $this->cleanParams();
        $this->setContext();
    }

    /**
     * @return null|string
     */
    private function extractSourceFromBacktrace()
    {
        if (!$this->backtrace->hasLines()) {
            return null;
        }

        if ($this->backtrace->hasApplicationLines()) {
            $line = $this->backtrace->application_lines[0];
        } else {
            $line = $this->backtrace->lines[0];
        }

        return $line->source($this->source_extract_radius);
    }

    /**
     *
     */
    private function findSessionData()
    {
        if (!$this->send_request_session) {
            return;
        }

        if (isset($this->args['session_data'])) {
            $this->session_data = $this->args['session_data'];
        } elseif (isset($this->args['session'])) {
            $this->session_data = $this->args['session'];
        } elseif (isset($_SESSION)) {
            $this->session_data = $_SESSION;
        }
    }

    /**
     *
     */
    private function cleanParams()
    {
        $this->filter($this->params);

        if ($this->cgi_data) {
            $this->filter($this->cgi_data);
        }

        if ($this->session_data) {
            $this->filter($this->session_data);
        }
    }

    /**
     * @param $params
     */
    private function filter(&$params)
    {
        if (empty($this->params_filters)) {
            return;
        }

        $params = Filter::params($this->params_filters, $params);
    }

    /**
     *
     */
    private function setContext()
    {
        $this->context = Honeybadger::context();

        if (isset($this->args['context']) and is_array($this->args['context'])) {
            $this->context = array_merge($this->context, $this->args['context']);
        }

        if (empty($this->context)) {
            $this->context = null;
        }
    }

    /**
     * Constructs and returns a new `Notice` with supplied options merged with
     * [Honeybadger::$config].
     *
     * @param  $options  array of Notice options.
     *
     * @return  Notice  The constructed notice.
     */
    public static function factory(array $options = [])
    {
        Honeybadger::init(); // ensure prior initialization

        return new self(Honeybadger::$config->merge($options));
    }

    /**
     * @return bool
     */
    public function isIgnored()
    {
        if (Filter::ignoreByClass($this->ignore, $this->exception)) {
            return true;
        }

        foreach ($this->ignore_by_filters as $filter) {
            if (call_user_func($filter, $this)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return mixed
     */
    public function deliver()
    {
        return Honeybadger::$sender->sendToHoneybadger($this);
    }

    /**
     * @return array
     */
    public function asArray()
    {
        $cgi_data = $this->cgi_data->asArray();

        return [
            'notifier' => [
                'name'     => $this->notifier_name,
                'url'      => $this->notifier_url,
                'version'  => $this->notifier_version,
                'language' => 'php',
            ],
            'error'    => [
                'class'     => $this->error_class,
                'message'   => $this->error_message,
                'backtrace' => $this->backtrace->asArray(),
                'source'    => $this->source_extract ?: null,
            ],
            'request'  => [
                'url'       => $this->url,
                'component' => $this->component,
                'action'    => $this->action,
                'params'    => empty($this->params) ? null : $this->params,
                'session'   => empty($this->session_data) ? null : $this->session_data,
                'cgi_data'  => empty($cgi_data) ? null : $cgi_data,
                'context'   => $this->context,
            ],
            'server'   => [
                'project_root'     => $this->project_root,
                'environment_name' => $this->environment_name,
                'hostname'         => $this->hostname,
            ],
        ];
    }
} // End Notice
