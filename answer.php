<?php

namespace FpDbTest;

use Exception;
use mysqli;

interface DatabaseInterface
{
    public function buildQuery(string $query, array $args = []): string;

    public function skip();
}

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $sql = '';

        $regex = '/\{((?:(?!\{|\}).)*)\}/';
        preg_match_all($regex, $query, $matches);

        if (!empty($matches[0])) {
            foreach ($matches[0] as $block) {
                $blockContent = $matches[1][array_search($block, $matches[0])];
                if ($this->checkBlock($blockContent, $args)) {
                    $query = str_replace($block, '', $query);
                }
            }
        }

        $tokens = preg_split('/(\?\??[dfas#])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($tokens as $token) {
            if ($token === '?') {
                $sql .= 'NULL';
            } elseif ($token === '?d' || $token === '?f') {
                $value = array_shift($args);
                if ($value === null) {
                    $sql .= 'NULL';
                } else {
                    $sql .= ($token === '?d') ? intval($value) : floatval($value);
                }
            } elseif ($token === '?a') {
                $value = array_shift($args);
                if ($value === null) {
                    $sql .= 'NULL';
                } else {
                    $sql .= $this->formatArray($value);
                }
            } elseif ($token === '?#') {
                $value = array_shift($args);
                if ($value === null) {
                    $sql .= 'NULL';
                } else {
                    $sql .= $this->formatIdentifiers($value);
                }
            } elseif ($token === '??d' || $token === '??f') {
                // Skip condition, do nothing
            } else {
                $sql .= $this->escapeValue($token);
            }
        }

        return $sql;
    }

    public function skip()
    {
        throw new Exception('Skip method called.');
    }

    private function checkBlock($block, $args)
    {
        $tokens = preg_split('/(\?\??[dfas#])/', $block, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($tokens as $token) {
            if ($token === '??d' || $token === '??f') {
                $value = array_shift($args);
                if ($value !== null) {
                    return true; // Skip the block
                }
            }
        }

        return false; // Include the block
    }

    private function formatArray($array)
    {
        $formattedValues = array_map(
            function ($value) {
                return $this->escapeValue($value);
            },
            $array
        );

        return implode(', ', $formattedValues);
    }

    private function formatIdentifiers($array)
    {
        $formattedValues = array_map(
            function ($value) {
                return $this->escapeIdentifier($value);
            },
            $array
        );

        return implode(', ', $formattedValues);
    }

    private function escapeValue($value)
    {
        // Implement your logic for escaping values
        return "'" . addslashes($value) . "'";
    }

    private function escapeIdentifier($identifier)
    {
        // Implement your logic for escaping identifiers
        return "`" . addslashes($identifier) . "`";
    }
}

class DatabaseTest
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function testBuildQuery(): void
    {
        $results = [];

        $results[] = $this->db->buildQuery('SELECT name FROM users WHERE user_id = 1');

        $results[] = $this->db->buildQuery(
            'SELECT * FROM users WHERE name = ? AND block = 0',
            ['Jack']
        );

        $results[] = $this->db->buildQuery(
            'SELECT ?# FROM users WHERE user_id = ?d AND block = ?d',
            [['name', 'email'], 2, true]
        );

        $results[] = $this->db->buildQuery(
            'UPDATE users SET ?a WHERE user_id = -1',
            [['name' => 'Jack', 'email' => null]]
        );

        foreach ([null, true] as $block) {
            $results[] = $this->db->buildQuery(
                'SELECT name FROM users WHERE ?# IN (?a){ AND block = ?d}',
                ['user_id', [1, 2, 3], $block ?? $this->db->skip()]
            );
        }

        $correct = [
            'SELECT name FROM users WHERE user_id = 1',
            'SELECT * FROM users WHERE name = \'Jack\' AND block = 0',
            'SELECT `name`, `email` FROM users WHERE user_id = 2 AND block = 1',
            'UPDATE users SET `name` = \'Jack\', `email` = NULL WHERE user_id = -1',
            'SELECT name FROM users WHERE `user_id` IN (1, 2, 3)',
            'SELECT name FROM users WHERE `user_id` IN (1, 2, 3) AND block = 1',
        ];

        if ($results !== $correct) {
            throw new Exception('Failure.');
        }
    }
}

// Example usage
$mysqli = new mysqli("localhost", "username", "password", "database");
$db = new Database($mysqli);
$test = new DatabaseTest($db);

try {
    $test->testBuildQuery();
    echo "Test passed successfully!\n";
} catch (Exception $e) {
    echo "Test failed: " . $e->getMessage() . "\n";
}