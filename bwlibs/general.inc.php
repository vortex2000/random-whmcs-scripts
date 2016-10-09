<?php
/**
 * Generate bcrypt hash for a password.
 *
 * @param string  Password to hash.
 * @param integer Bcrypt work factor. See
 *                http://wildlyinaccurate.com/bcrypt-choosing-a-work-factor for more
 *                information.
 *
 * @return string Bcrypt hash.
 */
function hash_password($password, $work_factor = 10)
{
    $urandom = @fopen('/dev/urandom', 'rb');
    if (($urandom === false) or (!$randstr = @fread($urandom, 16))) {
        return false;
    }
    $salt = substr(str_replace('+', '.', base64_encode($randstr)), 0, 22);
    @fclose($urandom);

    return crypt($password, '$2a$' . $work_factor . '$' . $salt);
}

/**
 * Verify whether or not a password matches a given crypt(2) hash.
 *
 * @param string Password
 * @param string Crypt(2) password
 *
 * @return boolean Value indicating whether or not the password matches a hash.
 */
function verify_password_hash($password, $hash)
{
    return $hash === crypt($password, $hash);
}

/**
 * HTML sanitize a value.
 *
 * @param string|array Value to sanitize. If this is an array, the array will
 *                           be sanitized recursively.
 *
 * @return string|array Sanitized value.
 */
function &html_sanitize(&$target)
{
    if (is_array($target)) {
        array_walk_recursive($target, 'html_sanitize');

        return $target;
    } else {
        if (is_numeric($target) or is_bool($target) or $target === null) {
            return $target;
        } else {
            return ($target = htmlspecialchars($target, ENT_QUOTES | ENT_IGNORE, 'UTF-8'));
        }
    }
}

/**
 * Return connection to the default database for the application. The
 * connection is normally statically cached for the duration of the script's
 * execution, but this can be changed with the "$new" option.
 *
 * @param boolean Force creation of a new connection instead of using the
 *                statically cached connection.
 *
 * @return PDOWrapper Database conection
 */
function get_default_db_connection($new = false)
{
    static $connection;

    if ($new or !$connection) {
        $_connection = DB::setup(DEFAULT_DB_HOST, DEFAULT_DB_USERNAME,
            DEFAULT_DB_PASSWORD, DEFAULT_DB_NAME);
        if (!$new) {
            $connection = $_connection;
        }

        return $_connection;
    }

    return $connection;
}
