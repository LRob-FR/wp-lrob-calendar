<?php
/**
 * Recurrence handling (RRULE parser and instance generator)
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Recurrence {

    private int $start;
    private int $end;
    private DateInterval $duration;
    private string $rules;
    private string $exception_rules;
    private string $recurrence_dates;
    private string $exception_dates;
    private string $timezone;
    private DateTimeZone $tz;

    // Safety limits — both configurable via plugin settings to fit sites with
    // unusually long recurring events (e.g. daily for a decade).
    private int $max_instances;
    private int $max_years;

    public function __construct(
        int $start,
        int $end,
        string $rules,
        string $exception_rules = '',
        string $recurrence_dates = '',
        string $exception_dates = '',
        string $timezone = 'UTC'
    ) {
        $this->start = $start;
        $this->end = $end;
        $this->rules = $rules;
        $this->exception_rules = $exception_rules;
        $this->recurrence_dates = $recurrence_dates;
        $this->exception_dates = $exception_dates;
        $this->timezone = $timezone ?: 'UTC';
        $this->tz = new DateTimeZone($this->timezone);

        $this->max_instances = max(1, (int) get_option('lrob_calendar_max_recurrence_instances', 500));
        $this->max_years     = max(1, (int) get_option('lrob_calendar_max_recurrence_years', 5));

        // Store duration as a DateInterval (wall-clock units) so we can re-apply it
        // to each instance via DateTime::add() — yields the correct end time across
        // DST transitions instead of a fixed integer offset.
        $start_dt = (new DateTime('@' . $start))->setTimezone($this->tz);
        $end_dt   = (new DateTime('@' . $end))->setTimezone($this->tz);
        $this->duration = $start_dt->diff($end_dt);
    }

    /**
     * Compute an instance's end timestamp by re-applying the wall-clock duration
     * to the given start timestamp in the event's timezone.
     */
    private function instance_end(int $start_timestamp): int {
        $dt = (new DateTime('@' . $start_timestamp))->setTimezone($this->tz);
        $dt->add($this->duration);
        return $dt->getTimestamp();
    }
    
    public function get_instances(): array {
        $instances = [];
        
        // Parse RRULE
        $rule = $this->parse_rrule($this->rules);
        if (!$rule) {
            return [];
        }
        
        // Get exception dates
        $exceptions = $this->parse_dates($this->exception_dates);
        
        // Generate instances from RRULE
        $generated = $this->generate_from_rule($rule);
        
        // Add RDATE instances
        $rdates = $this->parse_dates($this->recurrence_dates);
        foreach ($rdates as $rdate) {
            $generated[] = $rdate;
        }
        
        // Sort and filter
        sort($generated);
        
        foreach ($generated as $timestamp) {
            // Skip if in exception list
            if (in_array($timestamp, $exceptions)) {
                continue;
            }
            
            // Skip base event (already added separately)
            if ($timestamp === $this->start) {
                continue;
            }
            
            $instances[] = [
                'start' => $timestamp,
                'end' => $this->instance_end($timestamp),
            ];
            
            if (count($instances) >= $this->max_instances) {
                break;
            }
        }
        
        return $instances;
    }
    
    private function parse_rrule(string $rrule): ?array {
        if (empty($rrule)) {
            return null;
        }
        
        $rule = [];
        
        // Clean RRULE prefix if present
        $rrule = preg_replace('/^RRULE:/i', '', trim($rrule));
        
        $parts = explode(';', $rrule);
        foreach ($parts as $part) {
            if (strpos($part, '=') === false) {
                continue;
            }
            
            [$key, $value] = explode('=', $part, 2);
            $key = strtoupper(trim($key));
            $value = trim($value);
            
            switch ($key) {
                case 'FREQ':
                    $rule['freq'] = strtoupper($value);
                    break;
                case 'INTERVAL':
                    $rule['interval'] = max(1, (int) $value);
                    break;
                case 'COUNT':
                    $rule['count'] = (int) $value;
                    break;
                case 'UNTIL':
                    $rule['until'] = $this->parse_ical_datetime($value);
                    break;
                case 'BYDAY':
                    $rule['byday'] = array_map('trim', explode(',', $value));
                    break;
                case 'BYMONTH':
                    $rule['bymonth'] = array_map('intval', explode(',', $value));
                    break;
                case 'BYMONTHDAY':
                    $rule['bymonthday'] = array_map('intval', explode(',', $value));
                    break;
                case 'BYYEARDAY':
                    $rule['byyearday'] = array_map('intval', explode(',', $value));
                    break;
                case 'BYWEEKNO':
                    $rule['byweekno'] = array_map('intval', explode(',', $value));
                    break;
                case 'BYSETPOS':
                    $rule['bysetpos'] = array_map('intval', explode(',', $value));
                    break;
                case 'WKST':
                    $rule['wkst'] = strtoupper($value);
                    break;
            }
        }
        
        if (!isset($rule['freq'])) {
            return null;
        }
        
        $rule['interval'] = $rule['interval'] ?? 1;
        
        return $rule;
    }
    
    private function generate_from_rule(array $rule): array {
        // MONTHLY + BYDAY needs candidate expansion per month — a single "advance"
        // step can yield multiple dates (e.g. BYDAY=1MO,3WE → first Mon + third Wed),
        // and unordered BYDAY (e.g. BYDAY=MO,WE,FR) means every matching day in the month.
        if ($rule['freq'] === 'MONTHLY' && isset($rule['byday'])) {
            return $this->generate_monthly_byday($rule);
        }

        $dates = [];
        $start_dt = (new DateTime('@' . $this->start))->setTimezone($this->tz);

        // Determine end boundary
        $max_end = time() + ($this->max_years * 365 * 24 * 60 * 60);
        $until = $rule['until'] ?? $max_end;
        $until = min($until, $max_end);

        $count = $rule['count'] ?? $this->max_instances;
        $count = min($count, $this->max_instances);

        $current = clone $start_dt;
        $generated = 0;

        // Add start date
        $dates[] = $this->start;
        $generated++;

        while ($generated < $count && $current->getTimestamp() < $until) {
            // Advance to next occurrence
            $current = $this->advance_date($current, $rule);

            if ($current->getTimestamp() > $until) {
                break;
            }

            // Check BYDAY, BYMONTH, etc. filters
            if (!$this->matches_filters($current, $rule)) {
                continue;
            }

            $dates[] = $current->getTimestamp();
            $generated++;
        }

        return $dates;
    }

    /**
     * Generate dates for MONTHLY + BYDAY rules.
     *
     * Supports:
     *   - Multiple BYDAY entries (e.g. BYDAY=MO,WE,FR)
     *   - Ordinal prefixes (e.g. BYDAY=1MO, BYDAY=-1FR)
     *   - Mixed: BYDAY=1MO,3WE,-1FR
     *   - Unordered weekdays: BYDAY=MO,WE expands to every Mon + Wed in the month
     */
    private function generate_monthly_byday(array $rule): array {
        $start_dt = (new DateTime('@' . $this->start))->setTimezone($this->tz);

        $max_end = time() + ($this->max_years * 365 * 24 * 60 * 60);
        $until   = min($rule['until'] ?? $max_end, $max_end);
        $count   = min($rule['count'] ?? $this->max_instances, $this->max_instances);
        $interval = $rule['interval'];

        // Wall-clock time-of-day to apply to every generated date
        $hour   = (int) $start_dt->format('H');
        $minute = (int) $start_dt->format('i');
        $second = (int) $start_dt->format('s');

        $dates = [$this->start];
        $generated = 1;

        $year  = (int) $start_dt->format('Y');
        $month = (int) $start_dt->format('n');

        // Safety: cap on month-iteration count
        $max_months = $this->max_years * 12 + 12;

        for ($step = 0; $step < $max_months; $step++) {
            $month += $interval;
            while ($month > 12) {
                $month -= 12;
                $year++;
            }

            $days = $this->expand_byday_for_month($year, $month, $rule['byday']);
            if (empty($days)) {
                continue;
            }
            sort($days);

            foreach ($days as $day) {
                $dt = new DateTime('now', $this->tz);
                $dt->setDate($year, $month, $day);
                $dt->setTime($hour, $minute, $second);
                $ts = $dt->getTimestamp();

                if ($ts <= $this->start) {
                    continue;
                }
                if ($ts > $until) {
                    return $dates;
                }
                if (!$this->matches_filters($dt, $rule)) {
                    continue;
                }

                $dates[] = $ts;
                $generated++;
                if ($generated >= $count) {
                    return $dates;
                }
            }
        }

        return $dates;
    }

    /**
     * Return all days-of-month (1..31) matching the BYDAY list for the given month.
     *
     * Each BYDAY entry is either a plain weekday code (MO/TU/.../SU) or a weekday
     * code prefixed with an ordinal (1MO, -1FR, etc.). No prefix means "every
     * matching weekday in the month".
     */
    private function expand_byday_for_month(int $year, int $month, array $byday): array {
        $day_map = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];
        $first_ts = mktime(12, 0, 0, $month, 1, $year);
        $weekday_of_first = (int) date('w', $first_ts);
        $days_in_month = (int) date('t', $first_ts);

        $result = [];

        foreach ($byday as $entry) {
            $entry = strtoupper(trim($entry));
            if (!preg_match('/^(-?\d)?([A-Z]{2})$/', $entry, $matches)) {
                continue;
            }
            $nth = $matches[1] !== '' ? (int) $matches[1] : 0;
            $code = $matches[2];
            if (!isset($day_map[$code])) {
                continue;
            }

            $target_weekday = $day_map[$code];
            $first_match = 1 + (($target_weekday - $weekday_of_first + 7) % 7);

            $matches_in_month = [];
            for ($d = $first_match; $d <= $days_in_month; $d += 7) {
                $matches_in_month[] = $d;
            }

            if ($nth === 0) {
                $result = array_merge($result, $matches_in_month);
            } elseif ($nth > 0) {
                if (isset($matches_in_month[$nth - 1])) {
                    $result[] = $matches_in_month[$nth - 1];
                }
            } else {
                $idx = count($matches_in_month) + $nth;
                if ($idx >= 0 && isset($matches_in_month[$idx])) {
                    $result[] = $matches_in_month[$idx];
                }
            }
        }

        return array_values(array_unique($result));
    }
    
    private function advance_date(DateTime $date, array $rule): DateTime {
        $interval = $rule['interval'];
        
        switch ($rule['freq']) {
            case 'DAILY':
                $date->modify("+{$interval} day");
                break;
                
            case 'WEEKLY':
                if (isset($rule['byday'])) {
                    // Find next matching day
                    $date->modify('+1 day');
                    $safety = 0;
                    while (!$this->day_matches($date, $rule['byday']) && $safety < 14) {
                        $date->modify('+1 day');
                        $safety++;
                    }
                } else {
                    $date->modify("+{$interval} week");
                }
                break;
                
            case 'MONTHLY':
                // MONTHLY + BYDAY is handled separately by generate_monthly_byday().
                if (isset($rule['bymonthday'])) {
                    $date->modify("+{$interval} month");
                    $day = $rule['bymonthday'][0];
                    $date->setDate($date->format('Y'), $date->format('m'), min($day, $date->format('t')));
                } else {
                    $date->modify("+{$interval} month");
                }
                break;
                
            case 'YEARLY':
                $date->modify("+{$interval} year");
                break;
                
            default:
                $date->modify('+1 day');
        }
        
        return $date;
    }
    
    private function day_matches(DateTime $date, array $byday): bool {
        $dayMap = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];
        $currentDay = (int) $date->format('w');
        
        foreach ($byday as $day) {
            $day = preg_replace('/^-?\d/', '', strtoupper($day));
            if (isset($dayMap[$day]) && $dayMap[$day] === $currentDay) {
                return true;
            }
        }
        
        return false;
    }
    
    private function matches_filters(DateTime $date, array $rule): bool {
        // BYMONTH filter
        if (isset($rule['bymonth'])) {
            if (!in_array((int) $date->format('n'), $rule['bymonth'])) {
                return false;
            }
        }
        
        // BYMONTHDAY filter
        if (isset($rule['bymonthday'])) {
            if (!in_array((int) $date->format('j'), $rule['bymonthday'])) {
                return false;
            }
        }
        
        return true;
    }
    
    private function parse_dates(string $dates): array {
        if (empty($dates)) {
            return [];
        }
        
        $timestamps = [];
        $dates = preg_replace('/^[A-Z]+:/i', '', $dates);
        $parts = explode(',', $dates);
        
        foreach ($parts as $date) {
            $ts = $this->parse_ical_datetime(trim($date));
            if ($ts) {
                $timestamps[] = $ts;
            }
        }
        
        return $timestamps;
    }
    
    private function parse_ical_datetime(string $value): ?int {
        if (empty($value)) {
            return null;
        }
        
        // Format: 20231225T120000Z or 20231225T120000 or 20231225
        $value = trim($value);
        
        try {
            if (preg_match('/^(\d{8})(T(\d{6}))?(Z)?$/', $value, $matches)) {
                $dateStr = $matches[1];
                $timeStr = $matches[3] ?? '000000';
                $isUtc = isset($matches[4]);
                
                $year = substr($dateStr, 0, 4);
                $month = substr($dateStr, 4, 2);
                $day = substr($dateStr, 6, 2);
                $hour = substr($timeStr, 0, 2);
                $minute = substr($timeStr, 2, 2);
                $second = substr($timeStr, 4, 2);
                
                $tz = $isUtc ? new DateTimeZone('UTC') : $this->tz;
                $dt = new DateTime("{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}", $tz);
                
                return $dt->getTimestamp();
            }
        } catch (Exception $e) {
            return null;
        }
        
        return null;
    }
    
    // Static helper to create RRULE string
    
    public static function build_rrule(array $options): string {
        $parts = [];
        
        if (isset($options['freq'])) {
            $parts[] = 'FREQ=' . strtoupper($options['freq']);
        }
        
        if (isset($options['interval']) && $options['interval'] > 1) {
            $parts[] = 'INTERVAL=' . (int) $options['interval'];
        }
        
        if (isset($options['count'])) {
            $parts[] = 'COUNT=' . (int) $options['count'];
        }
        
        if (isset($options['until'])) {
            $dt = new DateTime('@' . $options['until']);
            $parts[] = 'UNTIL=' . $dt->format('Ymd\THis\Z');
        }
        
        if (isset($options['byday']) && is_array($options['byday'])) {
            $parts[] = 'BYDAY=' . implode(',', $options['byday']);
        }
        
        if (isset($options['bymonth']) && is_array($options['bymonth'])) {
            $parts[] = 'BYMONTH=' . implode(',', $options['bymonth']);
        }
        
        if (isset($options['bymonthday']) && is_array($options['bymonthday'])) {
            $parts[] = 'BYMONTHDAY=' . implode(',', $options['bymonthday']);
        }
        
        return implode(';', $parts);
    }
}
