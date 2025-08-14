<?php
namespace Staging2Live\Services;

if (!defined('ABSPATH')) {
	exit;
}

class DatabaseImportService {
	public function import_from_sql(string $sqlPath): bool {
		$sqlPath = wp_normalize_path($sqlPath);
		if (!file_exists($sqlPath)) {
			\Staging2Live\Services\LogService::write('Import failed: SQL path not found ' . $sqlPath);
			return false;
		}

		// Prefer WP-CLI
		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::runcommand('db import ' . escapeshellarg($sqlPath), [ 'return' => 'all', 'exit_error' => false ]);
			return true; // Assume success if no fatal
		}

        // Try mysql client for robust import
		if ($this->try_mysql_cli($sqlPath)) {
            return true;
        }

        // Fallback: run queries via mysqli with streaming parser
		global $wpdb;
		$mysqli = @new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
		if ($mysqli->connect_errno) {
			\Staging2Live\Services\LogService::write('Import failed: mysqli connect error ' . $mysqli->connect_error);
			return false;
		}
		$mysqli->set_charset('utf8mb4');
        // Improve import reliability
        @$mysqli->query('SET FOREIGN_KEY_CHECKS=0');
        @$mysqli->query('SET SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\'');
        @$mysqli->query('SET NAMES utf8mb4');
		@$mysqli->query('SET SESSION max_allowed_packet=67108864');
		$handle = fopen($sqlPath, 'r');
		if (!$handle) {
			\Staging2Live\Services\LogService::write('Import failed: could not open SQL file');
			$mysqli->close();
			return false;
		}
        $query = '';
        $inString = false;
        $stringChar = '';
        $hadError = false;
        $createdTables = 0;
		while (!feof($handle)) {
			$line = fgets($handle);
			if ($line === false) break;
            // Skip comments and statements that commonly cause issues in simple import
            if (preg_match('/^\s*--/', $line) || preg_match('/^\s*\/\*!/',$line) || preg_match('/^\s*LOCK TABLES/',$line) || preg_match('/^\s*UNLOCK TABLES/',$line) || preg_match('/^\s*SET /',$line) || trim($line) === '') {
				continue;
			}
            // Naive string-safe accumulator to avoid splitting on semicolons inside strings
            for ($i = 0, $len = strlen($line); $i < $len; $i++) {
                $ch = $line[$i];
                $query .= $ch;
                if ($inString) {
                    if ($ch === $stringChar && ($i === 0 || $line[$i-1] !== '\\')) {
                        $inString = false;
                        $stringChar = '';
                    }
                } else {
                    if ($ch === '\'' || $ch === '"') {
                        $inString = true;
                        $stringChar = $ch;
                    }
                }
            }
            if (!$inString && substr(rtrim($query), -1) === ';') {
                $ok = $mysqli->query($query);
                if (!$ok) {
                    \Staging2Live\Services\LogService::write('SQL error during import: ' . $mysqli->error);
                    $hadError = true;
                } else if (stripos($query, 'CREATE TABLE') !== false) {
                    $createdTables++;
                }
				$query = '';
			}
		}
		fclose($handle);
        @$mysqli->query('SET FOREIGN_KEY_CHECKS=1');
		$mysqli->close();
		$ok = (!$hadError || $createdTables > 0);
		if (!$ok) {
			\Staging2Live\Services\LogService::write('Import finished with errors and no tables created');
		}
		return $ok;
	}

    public function try_mysql_cli(string $sqlPath): bool {
        if (!function_exists('escapeshellarg')) {
            return false;
        }
        $mysql = trim(shell_exec('command -v mysql 2>/dev/null'));
        if ($mysql === '') {
            return false;
        }
        $dbName = defined('DB_NAME') ? DB_NAME : '';
        $dbUser = defined('DB_USER') ? DB_USER : '';
        $dbPass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
        $dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
        $host = $dbHost;
        $port = '';
        $socket = '';
        if (strpos($dbHost, ':') !== false) {
            list($h, $p) = explode(':', $dbHost, 2);
            $host = $h;
            if (strpos($p, '/') === 0) {
                $socket = $p;
            } else {
                $port = $p;
            }
        }
        $args = [];
        $args[] = '--default-character-set=utf8mb4';
        if ($host !== '') {
            $args[] = '--host=' . escapeshellarg($host);
        }
        if ($port !== '') {
            $args[] = '--port=' . escapeshellarg($port);
        }
        if ($socket !== '') {
            $args[] = '--socket=' . escapeshellarg($socket);
        }
        if ($dbUser !== '') {
            $args[] = '--user=' . escapeshellarg($dbUser);
        }
        if ($dbPass !== '') {
            $args[] = '--password=' . escapeshellarg($dbPass);
        }
        $args[] = escapeshellarg($dbName);
        $cmd = escapeshellcmd($mysql) . ' ' . implode(' ', $args) . ' < ' . escapeshellarg($sqlPath) . ' 2>/dev/null';
        shell_exec($cmd);
        return true; // best effort
    }
}


