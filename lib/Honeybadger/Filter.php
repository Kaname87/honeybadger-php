<?php

namespace Honeybadger;

class Filter {

	/**
	 * Applies a list of callbacks to an array of data.
	 *
	 * @param   array    $callbacks  Callbacks to run.
	 * @param   array    $data       Data to filter.
	 * @return  array    Filtered data
	 */
	public static function callbacks(array $callbacks, array $data)
	{
		if (empty($callbacks) OR empty($data))
			return $data;

		$filtered = array();

		foreach ($callbacks as $callback)
		{
			$data = $callback($data);

			// A filter wants to hide this data.
			if ($data === NULL)
				return;
		}

		foreach ($data as $key => $value)
		{
			$filtered['filtered_'.$key] = $value;
		}

		return $filtered;
	}

	/**
	 * Filters a supplied `array` of `$params`, searching for `$keys` and
	 * replacing each occurance with `[FILTERED]`.
	 *
	 * @param   array  $keys
	 * @param   array  $params Parameters to filter.
	 * @return  array  Filtered parameters.
	 */
	public static function params(array $keys = array(), array $params = array())
	{
		if (empty($keys) OR empty($params))
			return $params;

		foreach ($params as $param => &$value)
		{
			if (is_array($value))
			{
				$value = self::params($keys, $value);
			}
			elseif (in_array($param, $keys))
			{
				$value = '[FILTERED]';
			}
		}

		return $params;
	}

	/**
	 * Replaces occurances of configured project root with `[PROJECT_ROOT]` to
	 * simplify backtraces.
	 *
	 * @example
	 *     Filter::project_root(array(
	 *         'file' => 'path/to/my/app/models/user.php',
	 *     ));
	 *     // => array('file' => '[PROJECT_ROOT]/models/user.php')
	 *
	 * @param   array  Unparsed backtrace line.
	 * @return  array  Filtered backtrace line.
	 */
	public static function project_root($line)
	{
		if (empty(Honeybadger::$config->project_root))
			return $line;

		$pattern = '/'.preg_quote(Honeybadger::$config->project_root, '/').'/';
		$line['file'] = preg_replace($pattern, '[PROJECT_ROOT]', $line['file']);

		return $line;
	}

	// '\\Honeybadger\\Filter::relative_paths',
	// '\\Honeybadger\\Filter::honeybadger_paths',

	/**
	 * Attempts to expand paths to their real locations, if possible.
	 *
	 * @example
	 *     Filter::expand_paths(array(
	 *         'file' => '/etc/./../usr/local',
	 *     ));
	 *     // => array('file' => '/usr/local')
	 */
	public static function expand_paths($line)
	{
		if ($path = realpath($line['file']))
			$line['file'] = $path;

		return $line;
	}

	/**
	 * Removes Honeybadger from backtraces.
	 *
	 * @example
	 *     Filter::honeybadger_paths(array(
	 *         'file' => 'path/to/lib/Honeybadger/Honeybadger.php',
	 *     ));
	 *     // => NULL
	 */
	public static function honeybadger_paths($line)
	{
		if ( ! preg_match('/lib\/Honeybadger/', $line['file']))
			return $line;
	}

}