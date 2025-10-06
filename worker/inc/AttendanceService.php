<?php
// worker/inc/AttendanceService.php
// - OT In/Out are restricted to 6:00–10:00 PM (server-side)
// - AM/PM keep their time windows and clamps
// - OT Out clamps to OT end; auto-close can still run from caller

class AttendanceService {
  private mysqli $db;
  private array $sched;         // ['am_in'=>'07:00','am_out'=>'11:00','pm_in'=>'13:00','pm_out'=>'17:00']
  private int $standardMins;    // e.g., 480
  private int $graceMins;       // reserved
  private int $roundTo;         // minute rounding step (1 keeps exact)

  // Hard caps
  private const AM_OUT_HARDCAP  = '12:00:00'; // noon
  private const PM_OUT_HARDCAP  = '18:00:00'; // 6:00 PM

  // OT window (6:00 PM – 10:00 PM)
  private string $otStartHm = '18:00:00';
  private string $otEndHm   = '22:00:00';

  public function __construct(
    mysqli $db,
    array $sched,
    int $standardMins = 480,
    int $graceMins = 0,
    int $roundTo = 1
  ){
    $this->db = $db;
    $this->sched = $sched + ['am_in'=>'07:00','am_out'=>'11:00','pm_in'=>'13:00','pm_out'=>'17:00'];
    $this->standardMins = max(1,$standardMins);
    $this->graceMins = max(0,$graceMins);
    $this->roundTo = max(1,$roundTo);
  }

  public function otWindowHm(): array { return [$this->otStartHm, $this->otEndHm]; }
  public function otWindowTs(string $date): array {
    return [ strtotime("$date {$this->otStartHm}"), strtotime("$date {$this->otEndHm}") ];
  }

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

  public function computeDay(string $date, array $row): array {
    $hasAny = !empty($row['am_in']) || !empty($row['am_out']) || !empty($row['pm_in']) || !empty($row['pm_out']);

    $amIn  = $this->ts($date, $row['am_in']  ?? null);
    $amOut = $this->ts($date, $row['am_out'] ?? null);
    $pmIn  = $this->ts($date, $row['pm_in']  ?? null);
    $pmOut = $this->ts($date, $row['pm_out'] ?? null);
    $otIn  = $this->ts($date, $row['ot_in']  ?? null);
    $otOut = $this->ts($date, $row['ot_out'] ?? null);

    [$sAmStart,$sAmEnd, $sPmStart,$sPmEnd] = $this->segmentTs($date);

    // Regular inside schedule windows (07–11, 13–17)
    $workedAm = ($amIn && $amOut && $amOut > $amIn) ? $this->overlapMinutes([$amIn,$amOut], [$sAmStart,$sAmEnd]) : 0;
    $workedPm = ($pmIn && $pmOut && $pmOut > $pmIn) ? $this->overlapMinutes([$pmIn,$pmOut], [$sPmStart,$sPmEnd]) : 0;

    $regular = $this->roundMin($workedAm + $workedPm);
    $deduct  = $hasAny ? max(0, $this->standardMins - $regular) : 0;

    // OT minutes: prefer explicit OT pair; else beyond-standard if allowed
    $otAllowed = !empty($row['ot_allowed']) ? ((int)$row['ot_allowed'] === 1) : false;
    $ot = 0;
    if ($otAllowed && $otIn && $otOut && $otOut > $otIn) {
      $ot = $this->roundMin( (int) floor(($otOut - $otIn)/60) );
    } elseif ($otAllowed && $regular > $this->standardMins) {
      $ot = $this->roundMin($regular - $this->standardMins);
    }

    return [
      'regular' => $regular,
      'deduct'  => $deduct,
      'ot'      => $ot,
      'worked'  => $regular,
    ];
  }

  public function punch(int $empId, string $date, string $slot, int $actorUserId = 0, string $source='web'): void {
    $slot = strtolower(trim($slot));
    if (!in_array($slot,['am_in','am_out','pm_in','pm_out','ot_in','ot_out'],true)) {
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

    // Pair/order checks
    if ($slot==='am_out' && empty($row['am_in'])) throw new \Exception('Please Time In for AM first.');
    if ($slot==='pm_out' && empty($row['pm_in'])) throw new \Exception('Please Time In for PM first.');
    if ($slot==='ot_in') {
      if (empty($row['ot_allowed'])) throw new \Exception('OT is not allowed today.');
      // PM Out NOT required — only time window
    }
    if ($slot==='ot_out' && empty($row['ot_in'])) throw new \Exception('Please record OT In first.');
    if (!empty($row[$slot])) throw new \Exception('Already recorded.');

    $nowTs = time();

    // Enforce time windows:
    if (in_array($slot,['am_in','am_out','pm_in','pm_out'],true)) {
      [$winStart,$winEnd] = $this->windowFor($date, $slot, $row);
      if ($nowTs < $winStart || $nowTs > $winEnd) throw new \Exception('Outside allowed time window.');
    } else {
      // OT window 18:00–22:00
      [$otStart,$otEnd] = $this->otWindowTs($date);
      if ($nowTs < $otStart || $nowTs > $otEnd) throw new \Exception('Outside OT window (6:00–10:00 PM).');
    }

    // Clamp outs
    $valTs = $nowTs;
    if ($slot==='am_out') {
      $noon = strtotime($date.' '.self::AM_OUT_HARDCAP);
      if ($valTs > $noon) $valTs = $noon;
    }
    if ($slot==='pm_out') {
      $cap = strtotime($date.' '.self::PM_OUT_HARDCAP);
      if ($valTs > $cap) $valTs = $cap;
    }
    if ($slot==='ot_out') {
      [,$otEnd] = $this->otWindowTs($date);
      if ($valTs > $otEnd) $valTs = $otEnd;
    }

    $value = date('H:i:s', $valTs);
    $sql = "UPDATE attendance SET $slot=?, updated_at=NOW() WHERE employee_id=? AND work_date=? LIMIT 1";
    $st  = $this->db->prepare($sql);
    if (!$st) throw new \Exception('DB error.');
    $st->bind_param('sis',$value,$empId,$date);
    $st->execute();
    $st->close();
  }

  public function canPunchNow(string $date, string $slot, array $row = []): bool {
    $now = time();
    if (in_array($slot,['ot_in','ot_out'],true)) {
      [$s,$e] = $this->otWindowTs($date);
      return ($now >= $s && $now <= $e);
    }
    [$s,$e] = $this->windowFor($date,$slot,$row);
    return ($now >= $s && $now <= $e);
  }

  /**
   * Windows (for AM/PM only):
   *  - AM In:  06:00–11:00
   *  - AM Out: sched AM start .. 12:00
   *  - PM In:  12:00–17:00
   *  - PM Out: sched PM start .. 18:00
   * OT: 18:00–22:00 (handled separately)
   */
  public function windowFor(string $date, string $slot, array $row = []): array {
    [$amStart,$amEnd,$pmStart,$pmEnd] = $this->segmentTs($date);
    switch ($slot) {
      case 'am_in':  $start = strtotime($date.' 06:00:00'); $end = strtotime($date.' 11:00:00'); break;
      case 'am_out': $start = $amStart; $end = strtotime($date.' '.self::AM_OUT_HARDCAP); break; // 12:00
      case 'pm_in':  $start = strtotime($date.' 12:00:00'); $end = strtotime($date.' 17:00:00'); break;
      case 'pm_out': $start = $pmStart; $end = strtotime($date.' '.self::PM_OUT_HARDCAP); break; // 18:00
      default:
        $start = strtotime($date.' 00:00:00'); $end = strtotime($date.' 23:59:59'); // fallback
    }
    if ($end < $start) $end = $start;
    return [$start,$end];
  }

  private function roundMin(int $m): int {
    if ($this->roundTo <= 1) return max(0,$m);
    $r = (int) (round($m / $this->roundTo) * $this->roundTo);
    return max(0,$r);
  }

  private function ts(string $date, ?string $t): ?int {
    $t = trim((string)$t);
    if ($t==='') return null;
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/',$t)) return strtotime($date.' '.$t);
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

  private function overlapMinutes(array $A, array $B): int {
    [$a1,$a2] = $A; [$b1,$b2] = $B;
    if (!$a1 || !$a2 || !$b1 || !$b2) return 0;
    $s = max($a1,$b1);
    $e = min($a2,$b2);
    if ($e <= $s) return 0;
    return (int) floor(($e - $s)/60);
  }
}
