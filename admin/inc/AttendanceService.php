<?php
// inc/AttendanceService.php

class AttendanceService {
  private mysqli $db;
  private array $sched;         // ['am_in'=>'07:00','am_out'=>'11:00','pm_in'=>'13:00','pm_out'=>'17:00']
  private int $standardMins;    // typically 480
  private int $graceMins;       // reserved
  private int $roundTo;         // minute rounding step

  private const AM_OUT_HARDCAP  = '12:00:00'; // noon cap for AM OUT
  private const ANY_OUT_HARDCAP = '22:00:00'; // 10 PM cap for any OUT

  public function __construct(
    mysqli $db,
    array $sched,
    int $standardMins = 480,
    int $graceMins = 0,
    int $roundTo = 1
  ){
    $this->db = $db;
    $this->sched = $sched + [
      'am_in'  => '07:00:00',
      'am_out' => '11:00:00',
      'pm_in'  => '13:00:00',
      'pm_out' => '17:00:00',
    ];
    $this->standardMins = max(1,$standardMins);
    $this->graceMins    = max(0,$graceMins);
    $this->roundTo      = max(1,$roundTo);
  }

  /* ===================== Queries ===================== */

  public function getDay(int $empId, string $date): ?array {
    $st = $this->db->prepare("SELECT * FROM attendance WHERE employee_id=? AND work_date=? LIMIT 1");
    if (!$st) return null;
    $st->bind_param('is',$empId,$date);
    $st->execute();
    $res = $st->get_result();
    $row = $res->fetch_assoc() ?: null;
    $st->close();
    return $row;
  }

  public function listRange(int $empId, string $from, string $to): array {
    $st = $this->db->prepare("SELECT * FROM attendance WHERE employee_id=? AND work_date BETWEEN ? AND ? ORDER BY work_date ASC");
    if (!$st) return [];
    $st->bind_param('iss',$empId,$from,$to);
    $st->execute();
    $res = $st->get_result();
    $out = $res->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $out ?: [];
  }

  /* ===================== Core metrics ===================== */

  /**
   * Returns (in MINUTES):
   *  - regular: minutes inside schedule (07–11 & 13–17) from real punches
   *  - deduct : per-minute late/undertime vs schedule edges (0 if no entries at all)
   *  - ot     : minutes beyond 480 from actual work (only if ot_allowed=1), OUTs hard-capped at 22:00
   *  - worked : raw worked minutes from real intervals
   */
  public function computeDay(string $date, array $row): array {
    $hasAny = !empty($row['am_in']) || !empty($row['am_out']) || !empty($row['pm_in']) || !empty($row['pm_out']);

    // Raw timestamps
    $amIn  = $this->ts($date, $row['am_in']  ?? null);
    $amOut = $this->ts($date, $row['am_out'] ?? null);
    $pmIn  = $this->ts($date, $row['pm_in']  ?? null);
    $pmOut = $this->ts($date, $row['pm_out'] ?? null);

    // Hard caps on OUTs
    $noon  = strtotime($date.' '.self::AM_OUT_HARDCAP);
    $cap22 = strtotime($date.' '.self::ANY_OUT_HARDCAP);
    if ($amOut) $amOut = min($amOut, $noon);
    if ($pmOut) $pmOut = min($pmOut, $cap22);

    // Build actual worked intervals
    $intervals = [];
    if ($amIn && $amOut && $amOut > $amIn) $intervals[] = [$amIn,$amOut];
    if ($pmIn && $pmOut && $pmOut > $pmIn) $intervals[] = [$pmIn,$pmOut];
    // stitched day (AM in + PM out)
    if (!$intervals && $amIn && $pmOut && $pmOut > $amIn) $intervals[] = [$amIn,$pmOut];

    // Schedule windows
    [$sAmStart,$sAmEnd,$sPmStart,$sPmEnd] = $this->segmentTs($date);
    $windows = [[$sAmStart,$sAmEnd],[$sPmStart,$sPmEnd]];

    // Worked (raw) for OT
    $workedAll = $this->sumIntervalsMins($intervals);

    // Regular = overlap with schedule windows
    $regular = $this->overlapWithWindowsMins($intervals, $windows);
    $regular = $this->roundMin($regular);

    // --- Late Deduct: per segment against edges ---
    $late_am = 0;
    $late_pm = 0;

    if ($hasAny) {
      // AM presence?
      $amPresence = $this->overlapMinutes([$amIn,$amOut], [$sAmStart,$sAmEnd]) > 0
                 || ($amIn && !$amOut) || (!$amIn && $amOut);
      if ($amPresence) {
        $amInEff  = $amIn  ?? $sAmStart; // missing => assume edge for calculation
        $amOutEff = $amOut ?? $sAmEnd;
        $late_am  = max(0, (int)floor(($amInEff - $sAmStart)/60))   // arrived after 07:00
                  + max(0, (int)floor(($sAmEnd - $amOutEff)/60));   // left before 11:00
      } else {
        // worked that day but zero AM presence => entire AM missing
        $late_am = (int)floor(($sAmEnd - $sAmStart)/60); // 240
      }

      // PM presence?
      $pmPresence = $this->overlapMinutes([$pmIn,$pmOut], [$sPmStart,$sPmEnd]) > 0
                 || ($pmIn && !$pmOut) || (!$pmIn && $pmOut);
      if ($pmPresence) {
        $pmInEff  = $pmIn  ?? $sPmStart;
        $pmOutEff = $pmOut ?? $sPmEnd;
        $late_pm  = max(0, (int)floor(($pmInEff - $sPmStart)/60))   // arrived after 13:00
                  + max(0, (int)floor(($sPmEnd - $pmOutEff)/60));   // left before 17:00
      } else {
        $late_pm = (int)floor(($sPmEnd - $sPmStart)/60); // 240
      }
    }

    // If truly no entries at all => zero per your rule
    $deduct = $hasAny ? ($late_am + $late_pm) : 0;
    $deduct = $this->roundMin($deduct);

    // OT (only if allowed)
    $otAllowed = !empty($row['ot_allowed']) ? ((int)$row['ot_allowed'] === 1) : false;
    $otRaw     = max(0, $workedAll - $this->standardMins);
    $ot        = $otAllowed ? $this->roundMin($otRaw) : 0;

    return [
      'regular' => $regular,      // inside 7–11 & 13–17
      'deduct'  => $deduct,       // per-minute late/undertime (0 if truly no punches)
      'ot'      => $ot,           // beyond 480 from real intervals (if allowed)
      'worked'  => $workedAll,    // raw worked
    ];
  }

  /* ===================== Punch/undo/windows ===================== */

  public function punch(int $empId, string $date, string $slot, int $actorUserId = 0, string $source='web'): void {
    $slot = strtolower(trim($slot));
    if (!in_array($slot,['am_in','am_out','pm_in','pm_out'],true)) {
      throw new \Exception('Invalid slot.');
    }
    $row = $this->getDay($empId,$date);
    if (!$row) {
      $ins = $this->db->prepare("INSERT INTO attendance (employee_id, work_date, created_at) VALUES (?,?,NOW())");
      if (!$ins) throw new \Exception('DB error.');
      $ins->bind_param('is',$empId,$date);
      $ins->execute();
      $ins->close();
      $row = $this->getDay($empId,$date) ?? [];
    }
    if (!empty($row['paid'])) throw new \Exception('Day already marked as paid.');
    [$winStart,$winEnd] = $this->windowFor($date, $slot);
    if ($slot==='am_out' && empty($row['am_in'])) throw new \Exception('Log AM In first.');
    if ($slot==='pm_out' && empty($row['pm_in'])) throw new \Exception('Log PM In first.');
    if (!empty($row[$slot])) throw new \Exception('Already recorded.');

    $nowTs = time();
    if ($nowTs < $winStart || $nowTs > $winEnd) throw new \Exception('Outside allowed window.');

    $valTs = $nowTs;
    if ($slot==='am_out') {
      $noon   = strtotime($date.' '.self::AM_OUT_HARDCAP);
      if ($valTs > $noon) $valTs = $noon;
    }
    if ($slot==='pm_out') {
      $cap22  = strtotime($date.' '.self::ANY_OUT_HARDCAP);
      if ($valTs > $cap22) $valTs = $cap22;
    }

    $value = date('H:i:s', $valTs);
    $sql = "UPDATE attendance SET $slot=? WHERE employee_id=? AND work_date=? LIMIT 1";
    $st  = $this->db->prepare($sql);
    if (!$st) throw new \Exception('DB error.');
    $st->bind_param('sis',$value,$empId,$date);
    $st->execute();
    $st->close();
  }

  public function undoLast(int $empId, string $date, int $actorUserId=0): void {
    $row = $this->getDay($empId,$date);
    if (!$row) return;
    if (!empty($row['paid'])) throw new \Exception('Day is paid.');
    foreach (['pm_out','pm_in','am_out','am_in'] as $slot) {
      if (!empty($row[$slot])) {
        $sql = "UPDATE attendance SET $slot=NULL WHERE employee_id=? AND work_date=? LIMIT 1";
        $st  = $this->db->prepare($sql);
        if (!$st) throw new \Exception('DB error.');
        $st->bind_param('is',$empId,$date);
        $st->execute();
        $st->close();
        return;
      }
    }
  }

  public function canPunchNow(string $date, string $slot): bool {
    [$s,$e] = $this->windowFor($date,$slot);
    $now = time();
    return ($now >= $s && $now <= $e);
  }

  public function windowFor(string $date, string $slot): array {
    [$amStart,$amEnd,$pmStart,$pmEnd] = $this->segmentTs($date);
    $start=0; $end=0;
    switch (strtolower($slot)) {
      case 'am_in':  $start=$amStart; $end=min($amEnd, $amStart+2*3600); break;
      case 'am_out': $start=$amStart; $end=strtotime($date.' '.self::AM_OUT_HARDCAP); break;
      case 'pm_in':  $start=$pmStart; $end=min($pmEnd, $pmStart+2*3600); break;
      case 'pm_out': $start=$pmStart; $end=min(strtotime($date.' '.self::ANY_OUT_HARDCAP), max($pmEnd,$pmStart)); break;
      default:       $start=$amStart; $end=$amEnd;
    }
    if ($end < $start) $end = $start;
    return [$start,$end];
  }

  /* ===================== Internals ===================== */

  private function roundMin(int $m): int {
    if ($this->roundTo <= 1) return max(0,$m);
    $r = (int) (round($m / $this->roundTo) * $this->roundTo);
    return max(0,$r);
  }

  private function ts(string $date, ?string $t): ?int {
    $t = trim((string)$t);
    if ($t==='') return null;
    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$t)) return strtotime($date.' '.$t);
    $x = strtotime($t);
    return $x ?: null;
  }

  private function segmentTs(string $date): array {
    $amStart = $this->ts($date, $this->sched['am_in']);
    $amEnd   = $this->ts($date, $this->sched['am_out']);
    $pmStart = $this->ts($date, $this->sched['pm_in']);
    $pmEnd   = $this->ts($date, $this->sched['pm_out']);
    return [$amStart,$amEnd,$pmStart,$pmEnd];
  }

  private function sumIntervalsMins(array $intervals): int {
    $tot = 0;
    foreach ($intervals as [$i1,$i2]) {
      if ($i1 && $i2 && $i2 > $i1) $tot += (int)floor(($i2 - $i1)/60);
    }
    return $tot;
  }

  private function overlapMinutes(array $A, array $B): int {
    [$a1,$a2] = $A; [$b1,$b2] = $B;
    if (!$a1 || !$a2 || !$b1 || !$b2) return 0;
    $s = max($a1,$b1);
    $e = min($a2,$b2);
    if ($e <= $s) return 0;
    return (int) floor(($e - $s)/60);
  }

  private function overlapWithWindowsMins(array $intervals, array $windows): int {
    $tot = 0;
    foreach ($intervals as $int) {
      foreach ($windows as $win) {
        $tot += $this->overlapMinutes($int, $win);
      }
    }
    return $tot;
  }
}
