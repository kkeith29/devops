<?php

declare(strict_types=1);

namespace App\Classes;

/**
 * Class Process
 *
 * @package App\Classes
 */
class Process extends \Symfony\Component\Process\Process
{
    /**
     * Build option list from array
     *
     * @param array $options
     * @return string
     */
    public static function buildOptions(array $options): string
    {
        $args = '';
        $short = [];
        foreach ($options as $name => $value) {
            if (is_integer($name)) {
                $name = $value;
                if (is_array($value)) {
                    [$name, $value] = $value;
                } else {
                    $value = null;
                }
                if ($value === null) {
                    $short[] = $name;
                    continue;
                }
                if ($value === false) {
                    continue;
                }
                $args .= " -{$name} " . escapeshellarg($value);
                continue;
            }
            if ($value === false) {
                continue;
            }
            $args .= " --{$name}" . (is_string($value) ? '=' . escapeshellarg($value) : '');
        }
        if (count($short) > 0) {
            $args = ' -' . implode('', $short) . $args;
        }
        return $args;
    }
}
