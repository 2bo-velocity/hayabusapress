<?php
/**
 * HayabusaPress - Fixed daily publishing slots and the schedule they make.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * A site that publishes at the same times every day lists them once in the SEO settings
 * (publish_slots, e.g. "07:00,12:00,21:00"). The editor offers them as buttons and the admin
 * "Schedule" page lays posts out as days x slots. Times are in APP_TIMEZONE; posts store UTC.
 *
 * Admin-only: nothing here runs on public pages.
 */
final class PublishSlots
{
    public const MAX_SLOTS = 12;

    /** "7:00, 21:30,12:00" -> ['07:00','12:00','21:30'] (invalid entries dropped). */
    public static function parse(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $t, $m)) {
                $out[sprintf('%02d:%02d', (int)$m[1], (int)$m[2])] = true;
            }
        }
        $out = array_keys($out);
        sort($out);
        return array_slice($out, 0, self::MAX_SLOTS);
    }

    /** The configured slots. */
    public static function slots(): array
    {
        require_once CORE_PATH . '/SeoCompiler.php';
        return self::parse((string)(SeoCompiler::loadGlobal()['publish_slots'] ?? ''));
    }

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone(Env::get('APP_TIMEZONE', 'UTC'));
    }

    /**
     * The first slot after now that no scheduled or published post occupies, within $days days.
     *
     * @return string|null local 'Y-m-d\TH:i' (the datetime-local value), or null when none is free
     */
    public static function nextFree(int $days = 14): ?string
    {
        $slots = self::slots();
        if ($slots === []) {
            return null;
        }
        $tz  = self::tz();
        $now = new DateTimeImmutable('now', $tz);
        $utc = new DateTimeZone('UTC');
        $taken = [];
        foreach (DB::fetchAll(
            "SELECT published_at FROM posts
              WHERE status IN ('scheduled', 'published') AND deleted_at IS NULL
                AND published_at >= ? AND published_at < ?",
            [$now->setTimezone($utc)->format('Y-m-d H:i:s'), $now->modify("+{$days} days")->setTimezone($utc)->format('Y-m-d H:i:s')]
        ) as $r) {
            $taken[(new DateTimeImmutable((string)$r['published_at'], $utc))->setTimezone($tz)->format('Y-m-d H:i')] = true;
        }
        for ($d = 0; $d < $days; $d++) {
            $day = $now->modify("+{$d} days")->format('Y-m-d');
            foreach ($slots as $slot) {
                $at = new DateTimeImmutable("{$day} {$slot}", $tz);
                if ($at > $now && !isset($taken["{$day} {$slot}"])) {
                    return $at->format('Y-m-d\TH:i');
                }
            }
        }
        return null;
    }

    /**
     * Seven days of the schedule from $startDay (local 'Y-m-d').
     *
     * @return array{days:string[], slots:string[], cells:array<string,array<string,array>>, off:array<string,array>}
     *         cells[slot][day] and off[day] are lists of posts (id, title, status, category)
     */
    public static function week(string $startDay): array
    {
        $tz    = self::tz();
        $utc   = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($startDay . ' 00:00', $tz);
        $days  = [];
        for ($d = 0; $d < 7; $d++) {
            $days[] = $start->modify("+{$d} days")->format('Y-m-d');
        }
        $slots = self::slots();
        $cells = array_fill_keys($slots, array_fill_keys($days, []));
        $off   = array_fill_keys($days, []);

        $cats = [];
        foreach (DB::fetchAll('SELECT id, name FROM categories') as $c) {
            $cats[(int)$c['id']] = (string)$c['name'];
        }
        $rows = DB::fetchAll(
            "SELECT id, title, status, type, category_id, published_at FROM posts
              WHERE status IN ('scheduled', 'published') AND deleted_at IS NULL
                AND published_at >= ? AND published_at < ?
              ORDER BY published_at",
            [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $start->modify('+7 days')->setTimezone($utc)->format('Y-m-d H:i:s')]
        );
        foreach ($rows as $r) {
            $local = (new DateTimeImmutable((string)$r['published_at'], $utc))->setTimezone($tz);
            $day   = $local->format('Y-m-d');
            $item  = [
                'id'       => (int)$r['id'],
                'title'    => (string)$r['title'],
                'status'   => (string)$r['status'],
                'type'     => (string)$r['type'],
                'time'     => $local->format('H:i'),
                'category' => $cats[(int)($r['category_id'] ?? 0)] ?? '',
            ];
            if (isset($cells[$item['time']][$day])) {
                $cells[$item['time']][$day][] = $item;
            } elseif (isset($off[$day])) {
                $off[$day][] = $item;
            }
        }
        return ['days' => $days, 'slots' => $slots, 'cells' => $cells, 'off' => $off];
    }
}
