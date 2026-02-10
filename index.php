<?php
/**
 * ✅ Giveaway Bot (Webhook) — SINGLE FILE index.php
 *
 * ✅ 2 Force-Join channels
 * ✅ "✅ Verified!" message shows ONLY FIRST TIME (stored in users table)
 * ✅ Reply keyboard: 🎁 Participate in Giveaway
 *
 * ✅ Admin-generated codes:
 *    - 8 chars (5 letters + 3 numbers), shuffled
 *    - REUSABLE by unlimited users until expiry
 *    - EXPIRE after 1 minute
 * ✅ User can join giveaway only ONCE per giveaway
 *
 * ✅ Admin Panel:
 *    - ➕ Create Codes (shows generated codes)
 *    - 👥 Participants Count
 *    - 🎲 Choose Winners (random; shows winner NAMES only)
 *    - ✍️ Manual Select Winners (pick winners by participant number; names only)
 *    - 📨 Send Prize Codes (admin pastes codes -> bot sends to winners)
 *    - 🧹 Reset Giveaway (ends current, starts new)
 *
 * ❌ Winner announcement in channel/group: REMOVED
 *
 * REQUIRED ENV:
 * BOT_TOKEN, ADMIN_ID
 * DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 * FORCE_JOIN_1, FORCE_JOIN_2
 */

// ===================== CONFIG (ENV) =====================
$BOT_TOKEN = getenv("BOT_TOKEN");
$ADMIN_ID  = intval(getenv("ADMIN_ID"));

$DB_HOST = trim(getenv("DB_HOST") ?: "");
$DB_PORT = trim(getenv("DB_PORT") ?: "5432");
$DB_NAME = trim(getenv("DB_NAME") ?: "postgres");
$DB_USER = trim(getenv("DB_USER") ?: "");
$DB_PASS = getenv("DB_PASS");

$FORCE_JOIN_1 = trim(getenv("FORCE_JOIN_1") ?: "");
$FORCE_JOIN_2 = trim(getenv("FORCE_JOIN_2") ?: "");

// ===================== BASIC CHECKS =====================
if (!$BOT_TOKEN || !$ADMIN_ID || !$DB_HOST || !$DB_USER || $DB_PASS === false || $DB_PASS === null) {
  http_response_code(200);
  echo "Missing ENV";
  exit;
}

// ===================== DB (PDO) with SSL =====================
try {
  $dsn = "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};sslmode=require";
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 10
  ]);
} catch (Throwable $e) {
  error_log("DB CONNECT ERROR: " . $e->getMessage());
  http_response_code(200);
  echo "DB error";
  exit;
}

// ===================== TELEGRAM HELPERS =====================
function tg($method, $data = []) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
  curl_setopt($ch, CURLOPT_TIMEOUT, 18);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

function sendMessage($chat_id, $text, $reply_markup = null) {
  $data = [
    "chat_id" => $chat_id,
    "text" => $text,
    "parse_mode" => "HTML",
    "disable_web_page_preview" => true
  ];
  if ($reply_markup) $data["reply_markup"] = json_encode($reply_markup);
  return tg("sendMessage", $data);
}

function answerCb($cb_id, $text = "") {
  $data = ["callback_query_id" => $cb_id];
  if ($text !== "") $data["text"] = $text;
  return tg("answerCallbackQuery", $data);
}

function getChatMemberStatus($channel, $user_id) {
  $res = tg("getChatMember", ["chat_id" => $channel, "user_id" => $user_id]);
  if (!$res || empty($res["ok"])) return "left";
  return $res["result"]["status"] ?? "left";
}

function isJoined($channel, $user_id) {
  if (!$channel) return true;
  $st = getChatMemberStatus($channel, $user_id);
  return in_array($st, ["member", "administrator", "creator"], true);
}

// ===================== KEYBOARDS =====================
function mainMenuKeyboard($isAdmin = false) {
  $kb = [
    [["text" => "🎁 Participate in Giveaway"]],
  ];
  if ($isAdmin) $kb[] = [["text" => "🛠 Admin Panel"]];
  return ["keyboard" => $kb, "resize_keyboard" => true, "is_persistent" => true];
}

function adminKeyboard() {
  return [
    "keyboard" => [
      [["text" => "➕ Create Codes"], ["text" => "🎲 Choose Winners"]],
      [["text" => "✍️ Manual Select Winners"], ["text" => "👥 Participants Count"]],
      [["text" => "📨 Send Prize Codes"], ["text" => "🧹 Reset Giveaway"]],
      [["text" => "⬅️ Back"]]
    ],
    "resize_keyboard" => true,
    "is_persistent" => true
  ];
}

function forceJoinMarkup() {
  global $FORCE_JOIN_1, $FORCE_JOIN_2;
  $buttons = [];

  if ($FORCE_JOIN_1) {
    $url1 = (strpos($FORCE_JOIN_1, "@") === 0) ? "https://t.me/" . substr($FORCE_JOIN_1, 1) : "https://t.me/";
    $buttons[] = [["text" => "✅ Join Channel 1", "url" => $url1]];
  }
  if ($FORCE_JOIN_2) {
    $url2 = (strpos($FORCE_JOIN_2, "@") === 0) ? "https://t.me/" . substr($FORCE_JOIN_2, 1) : "https://t.me/";
    $buttons[] = [["text" => "✅ Join Channel 2", "url" => $url2]];
  }

  $buttons[] = [["text" => "✅ I've Joined (Check)", "callback_data" => "check_join"]];
  return ["inline_keyboard" => $buttons];
}

// ===================== USERS: VERIFIED ONCE =====================
function userIsVerified($tg_id) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT verified FROM users WHERE tg_id=:tg");
  $stmt->execute([":tg" => $tg_id]);
  $row = $stmt->fetch();
  return $row ? (bool)$row["verified"] : false;
}

function setUserVerified($tg_id) {
  global $pdo;
  $stmt = $pdo->prepare("
    INSERT INTO users (tg_id, verified, verified_at, updated_at)
    VALUES (:tg, TRUE, now(), now())
    ON CONFLICT (tg_id) DO UPDATE
      SET verified=TRUE, verified_at=COALESCE(users.verified_at, now()), updated_at=now()
  ");
  $stmt->execute([":tg" => $tg_id]);
}

// ===================== STATE (DB) =====================
function setState($tg_id, $state, $payload = "") {
  global $pdo;
  $stmt = $pdo->prepare("
    INSERT INTO bot_state (tg_id, state, payload, updated_at)
    VALUES (:tg, :st, :pl, now())
    ON CONFLICT (tg_id) DO UPDATE
      SET state=EXCLUDED.state, payload=EXCLUDED.payload, updated_at=now()
  ");
  $stmt->execute([":tg" => $tg_id, ":st" => $state, ":pl" => $payload]);
}

function getState($tg_id) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT state, payload FROM bot_state WHERE tg_id=:tg");
  $stmt->execute([":tg" => $tg_id]);
  return $stmt->fetch() ?: ["state" => null, "payload" => null];
}

function clearState($tg_id) {
  setState($tg_id, null, "");
}

// ===================== GIVEAWAY HELPERS =====================
function getActiveGiveawayId() {
  global $pdo;
  $pdo->exec("INSERT INTO giveaways (status)
              SELECT 'active'
              WHERE NOT EXISTS (SELECT 1 FROM giveaways WHERE status='active')");
  $stmt = $pdo->query("SELECT id FROM giveaways WHERE status='active' ORDER BY id DESC LIMIT 1");
  $row = $stmt->fetch();
  return $row ? intval($row["id"]) : null;
}

// 8 chars: 5 letters + 3 numbers, shuffled
function randomCode() {
  $letters = "ABCDEFGHJKLMNPQRSTUVWXYZ";
  $numbers = "23456789";
  $code = "";
  for ($i = 0; $i < 5; $i++) $code .= $letters[random_int(0, strlen($letters) - 1)];
  for ($i = 0; $i < 3; $i++) $code .= $numbers[random_int(0, strlen($numbers) - 1)];
  return str_shuffle($code);
}

// Create codes: reusable until expiry, expires in 1 minute
function createCodes($giveaway_id, $count) {
  global $pdo;
  $count = max(1, min(200, intval($count)));
  $created = [];

  $stmt = $pdo->prepare("
    INSERT INTO giveaway_codes (giveaway_id, code, expires_at)
    VALUES (:gid, :code, now() + interval '3 minute')
  ");

  while (count($created) < $count) {
    $code = randomCode();
    try {
      $stmt->execute([":gid" => $giveaway_id, ":code" => $code]);
      $created[] = $code;
    } catch (Exception $e) {
      // duplicate -> retry
    }
  }
  return $created;
}

// Unlimited-use code until expiry; user joins only once per giveaway
function joinWithCode($tg_id, $tg_name, $code) {
  global $pdo;
  $gid = getActiveGiveawayId();

  // already joined?
  $chk = $pdo->prepare("SELECT 1 FROM giveaway_participants WHERE giveaway_id=:gid AND tg_id=:tg LIMIT 1");
  $chk->execute([":gid" => $gid, ":tg" => $tg_id]);
  if ($chk->fetch()) return ["ok" => false, "msg" => "✅ You already joined this giveaway."];

  // validate code
  $stmt = $pdo->prepare("SELECT expires_at FROM giveaway_codes WHERE giveaway_id=:gid AND code=:c LIMIT 1");
  $stmt->execute([":gid" => $gid, ":c" => $code]);
  $row = $stmt->fetch();

  if (!$row) return ["ok" => false, "msg" => "❌ Invalid code."];

  // expiry check
  $expCheck = $pdo->prepare("SELECT (now() > :exp) AS expired");
  $expCheck->execute([":exp" => $row["expires_at"]]);
  $ex = $expCheck->fetch();
  if (!empty($ex) && !empty($ex["expired"])) {
    return ["ok" => false, "msg" => "⏳ Code expired (valid only 3 minute). Ask admin for a new code."];
  }

  // insert participant
  $ins = $pdo->prepare("INSERT INTO giveaway_participants (giveaway_id, tg_id, tg_name) VALUES (:gid, :tg, :nm)");
  $ins->execute([":gid" => $gid, ":tg" => $tg_id, ":nm" => $tg_name]);

  return ["ok" => true, "msg" => "✅ You are entered in the giveaway!"];
}

function getParticipantsCount($giveaway_id) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM giveaway_participants WHERE giveaway_id=:gid");
  $stmt->execute([":gid" => $giveaway_id]);
  $row = $stmt->fetch();
  return $row ? intval($row["c"]) : 0;
}

function getParticipants($giveaway_id, $limit = 50) {
  global $pdo;
  $limit = max(1, min(200, intval($limit)));
  // NOTE: LIMIT cannot be parameterized in PDO safely, so we clamp and inline.
  $stmt = $pdo->prepare("
    SELECT tg_id, tg_name
    FROM giveaway_participants
    WHERE giveaway_id=:gid
    ORDER BY joined_at ASC
    LIMIT {$limit}
  ");
  $stmt->execute([":gid" => $giveaway_id]);
  return $stmt->fetchAll();
}

function pickWinners($giveaway_id, $count) {
  global $pdo;
  $count = max(1, min(200, intval($count)));

  $stmt = $pdo->prepare("SELECT tg_id, tg_name FROM giveaway_participants WHERE giveaway_id=:gid");
  $stmt->execute([":gid" => $giveaway_id]);
  $all = $stmt->fetchAll();
  if (!$all) return ["ok" => false, "msg" => "No participants yet."];

  shuffle($all);
  $picked = array_slice($all, 0, min($count, count($all)));

  $pdo->beginTransaction();
  try {
    $pdo->prepare("DELETE FROM giveaway_winners WHERE giveaway_id=:gid")->execute([":gid" => $giveaway_id]);
    $ins = $pdo->prepare("INSERT INTO giveaway_winners (giveaway_id, tg_id, tg_name) VALUES (:gid, :tg, :nm)");
    foreach ($picked as $p) {
      $ins->execute([":gid" => $giveaway_id, ":tg" => $p["tg_id"], ":nm" => $p["tg_name"]]);
    }
    $pdo->commit();
    return ["ok" => true, "picked" => $picked];
  } catch (Exception $e) {
    $pdo->rollBack();
    return ["ok" => false, "msg" => "Error choosing winners."];
  }
}

function setWinnersManual($giveaway_id, $picked) {
  global $pdo;
  $pdo->beginTransaction();
  try {
    $pdo->prepare("DELETE FROM giveaway_winners WHERE giveaway_id=:gid")->execute([":gid" => $giveaway_id]);
    $ins = $pdo->prepare("INSERT INTO giveaway_winners (giveaway_id, tg_id, tg_name) VALUES (:gid, :tg, :nm)");
    foreach ($picked as $p) {
      $ins->execute([":gid" => $giveaway_id, ":tg" => $p["tg_id"], ":nm" => $p["tg_name"]]);
    }
    $pdo->commit();
    return true;
  } catch (Exception $e) {
    $pdo->rollBack();
    error_log("MANUAL WINNER ERROR: " . $e->getMessage());
    return false;
  }
}

function getWinners($giveaway_id) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT tg_id, tg_name FROM giveaway_winners WHERE giveaway_id=:gid ORDER BY id ASC");
  $stmt->execute([":gid" => $giveaway_id]);
  return $stmt->fetchAll();
}

function resetGiveaway() {
  global $pdo;
  $gid = getActiveGiveawayId();
  if (!$gid) return;

  $pdo->prepare("UPDATE giveaways SET status='ended', ended_at=now() WHERE id=:gid")->execute([":gid" => $gid]);
  $pdo->exec("INSERT INTO giveaways (status) VALUES ('active')");
}

// ===================== JOIN CHECK (Verified message only first time) =====================
function requireJoinOrPrompt($chat_id, $tg_id, $isAdmin, $sendMenu = false) {
  global $FORCE_JOIN_1, $FORCE_JOIN_2;

  $ok1 = isJoined($FORCE_JOIN_1, $tg_id);
  $ok2 = isJoined($FORCE_JOIN_2, $tg_id);

  if ($ok1 && $ok2) {
    $already = userIsVerified($tg_id);

    if (!$already) {
      setUserVerified($tg_id);
      sendMessage($chat_id, "✅ Verified!\n\nUse menu to participate giveaway.", mainMenuKeyboard($isAdmin));
    } elseif ($sendMenu) {
      sendMessage($chat_id, "Use menu ✅", mainMenuKeyboard($isAdmin));
    }
    return true;
  }

  sendMessage($chat_id, "⚠️ Please join both channels first, then tap <b>I've Joined (Check)</b>.", forceJoinMarkup());
  return false;
}

// ===================== UPDATE HANDLER =====================
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { echo "ok"; exit; }

$message  = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;

// ---- CALLBACKS ----
if ($callback) {
  $cb_id   = $callback["id"];
  $from    = $callback["from"];
  $tg_id   = intval($from["id"]);
  $chat_id = intval($callback["message"]["chat"]["id"]);
  $data    = $callback["data"] ?? "";
  $isAdmin = ($tg_id === $GLOBALS["ADMIN_ID"]);

  if ($data === "check_join") {
    answerCb($cb_id, "Checking...");
    requireJoinOrPrompt($chat_id, $tg_id, $isAdmin, true);
    echo "ok"; exit;
  }

  answerCb($cb_id);
  echo "ok"; exit;
}

// ---- MESSAGES ----
if ($message) {
  $chat_id = intval($message["chat"]["id"]);
  $from    = $message["from"];
  $tg_id   = intval($from["id"]);
  $first   = trim($from["first_name"] ?? "");
  $username= trim($from["username"] ?? "");
  $tg_name = trim($first . ($username ? " (@$username)" : ""));
  $text    = trim($message["text"] ?? "");
  $isAdmin = ($tg_id === $ADMIN_ID);

  if ($text === "/start") {
    requireJoinOrPrompt($chat_id, $tg_id, $isAdmin, true);
    echo "ok"; exit;
  }

  if ($isAdmin && $text === "🛠 Admin Panel") {
    clearState($tg_id);
    sendMessage($chat_id, "🛠 <b>Admin Panel</b>", adminKeyboard());
    echo "ok"; exit;
  }

  if ($text === "⬅️ Back") {
    clearState($tg_id);
    sendMessage($chat_id, "✅ Main menu", mainMenuKeyboard($isAdmin));
    echo "ok"; exit;
  }

  if ($text === "🎁 Participate in Giveaway") {
    if (!requireJoinOrPrompt($chat_id, $tg_id, $isAdmin, false)) { echo "ok"; exit; }
    setState($tg_id, "await_code", "");
    sendMessage(
      $chat_id,
      "🎁 Enter the <b>unique code</b> to participate in giveaway:\n\n⏳ Code expires in <b>3 minute</b>.\n✅ Same code can be used by many users until expiry."
    );
    echo "ok"; exit;
  }

  // ---------- ADMIN BUTTONS ----------
  if ($isAdmin && $text === "➕ Create Codes") {
    $gid = getActiveGiveawayId();
    setState($tg_id, "admin_create_codes", (string)$gid);
    sendMessage($chat_id, "➕ How many codes to create? (1 - 200)\n\n⏳ Each code expires in 3 minute.\n✅ Same code can be used by many users until expiry.");
    echo "ok"; exit;
  }

  if ($isAdmin && $text === "👥 Participants Count") {
    $gid = getActiveGiveawayId();
    $count = getParticipantsCount($gid);
    sendMessage($chat_id, "👥 Participants in current giveaway: <b>{$count}</b>", adminKeyboard());
    echo "ok"; exit;
  }

  if ($isAdmin && $text === "🎲 Choose Winners") {
    $gid = getActiveGiveawayId();
    setState($tg_id, "admin_choose_winners", (string)$gid);
    sendMessage($chat_id, "🎲 How many winners you want? (example: 3)");
    echo "ok"; exit;
  }

  if ($isAdmin && $text === "✍️ Manual Select Winners") {
    $gid = getActiveGiveawayId();
    $participants = getParticipants($gid, 50);

    if (!$participants) {
      sendMessage($chat_id, "❌ No participants yet.", adminKeyboard());
      echo "ok"; exit;
    }

    setState($tg_id, "admin_manual_select", json_encode($participants));

    $msg = "✍️ <b>Manual Select Winners</b>\n\n";
    $i = 1;
    foreach ($participants as $p) {
      $msg .= $i . ") " . htmlspecialchars($p["tg_name"]) . "\n";
      $i++;
    }
    $msg .= "\nReply with winner numbers like:\n<code>1,3,5</code>";

    sendMessage($chat_id, $msg, adminKeyboard());
    echo "ok"; exit;
  }

  if ($isAdmin && $text === "📨 Send Prize Codes") {
    $gid = getActiveGiveawayId();
    $w = getWinners($gid);
    if (!$w) {
      sendMessage($chat_id, "❌ No winners chosen yet. Use 🎲 Choose Winners or ✍️ Manual Select Winners", adminKeyboard());
      echo "ok"; exit;
    }
    setState($tg_id, "admin_send_prizes", (string)$gid);
    $n = count($w);
    sendMessage($chat_id, "📨 Send <b>{$n}</b> prize codes now.\n\nSend each code on a new line.");
    echo "ok"; exit;
  }

  if ($isAdmin && $text === "🧹 Reset Giveaway") {
    resetGiveaway();
    clearState($tg_id);
    sendMessage($chat_id, "🧹 Giveaway reset ✅\nNew giveaway started.\nNow create new codes.", adminKeyboard());
    echo "ok"; exit;
  }

  // ---------- STATE MACHINE ----------
  $st = getState($tg_id);
  $state = $st["state"];

  if ($state === "await_code") {
    if (!requireJoinOrPrompt($chat_id, $tg_id, $isAdmin, false)) { echo "ok"; exit; }

    $code = strtoupper(preg_replace("/\s+/", "", $text));
    if (strlen($code) !== 8) {
      sendMessage($chat_id, "❌ Code must be <b>8 characters</b> (5 letters + 3 numbers). Try again:");
      echo "ok"; exit;
    }

    $res = joinWithCode($tg_id, $tg_name, $code);
    clearState($tg_id);
    sendMessage($chat_id, $res["msg"], mainMenuKeyboard($isAdmin));
    echo "ok"; exit;
  }

  if ($isAdmin && $state === "admin_create_codes") {
    $gid = intval($st["payload"]);
    $num = intval(preg_replace("/[^0-9]/", "", $text));
    if ($num < 1 || $num > 200) {
      sendMessage($chat_id, "❌ Enter a number between 1 and 200:");
      echo "ok"; exit;
    }

    $codes = createCodes($gid, $num);
    clearState($tg_id);

    $msg = "✅ <b>Generated Giveaway Codes</b>\n\n";
    foreach ($codes as $c) $msg .= "<code>{$c}</code>\n";
    $msg .= "\n⏳ Each code expires in <b>3 minute</b>.\n✅ Same code can be used by many users until expiry.";

    sendMessage($chat_id, $msg, adminKeyboard());
    echo "ok"; exit;
  }

  if ($isAdmin && $state === "admin_choose_winners") {
    $gid = intval($st["payload"]);
    $num = intval(preg_replace("/[^0-9]/", "", $text));
    if ($num < 1 || $num > 200) {
      sendMessage($chat_id, "❌ Enter a number between 1 and 200:");
      echo "ok"; exit;
    }

    $pick = pickWinners($gid, $num);
    clearState($tg_id);

    if (!$pick["ok"]) {
      sendMessage($chat_id, "❌ " . $pick["msg"], adminKeyboard());
      echo "ok"; exit;
    }

    $picked = $pick["picked"];
    $out = "🎉 <b>Winners Chosen</b>\n\n";
    $i = 1;
    foreach ($picked as $p) {
      $name = trim($p["tg_name"] ?? "");
      if ($name === "") $name = "Winner #{$i}";
      $out .= $i . ") " . htmlspecialchars($name) . "\n";
      $i++;
    }
    $out .= "\nNow tap: 📨 Send Prize Codes";

    sendMessage($chat_id, $out, adminKeyboard());
    echo "ok"; exit;
  }

  if ($isAdmin && $state === "admin_manual_select") {
    $gid = getActiveGiveawayId();

    $list = json_decode($st["payload"] ?: "[]", true);
    if (!$list) {
      clearState($tg_id);
      sendMessage($chat_id, "❌ Participant list expired. Tap ✍️ Manual Select Winners again.", adminKeyboard());
      echo "ok"; exit;
    }

    $raw = preg_replace("/[^0-9, ]/", "", $text);
    $parts = preg_split("/[,\s]+/", trim($raw));
    $nums = [];
    foreach ($parts as $p) {
      if ($p === "") continue;
      $n = intval($p);
      if ($n > 0) $nums[] = $n;
    }
    $nums = array_values(array_unique($nums));

    if (!$nums) {
      sendMessage($chat_id, "❌ Send winner numbers like: <code>1,3,5</code>");
      echo "ok"; exit;
    }

    $picked = [];
    foreach ($nums as $n) {
      $idx = $n - 1;
      if (isset($list[$idx])) $picked[] = $list[$idx];
    }

    if (!$picked) {
      sendMessage($chat_id, "❌ Invalid numbers. Try again like: <code>1,3,5</code>");
      echo "ok"; exit;
    }

    $ok = setWinnersManual($gid, $picked);
    clearState($tg_id);

    if (!$ok) {
      sendMessage($chat_id, "❌ Failed to save winners. Try again.", adminKeyboard());
      echo "ok"; exit;
    }

    $out = "✅ <b>Manual winners saved</b>\n\n";
    $i = 1;
    foreach ($picked as $p) {
      $out .= $i . ") " . htmlspecialchars($p["tg_name"]) . "\n";
      $i++;
    }
    $out .= "\nNow tap: 📨 Send Prize Codes";

    sendMessage($chat_id, $out, adminKeyboard());
    echo "ok"; exit;
  }

  if ($isAdmin && $state === "admin_send_prizes") {
    $gid = intval($st["payload"]);
    $winners = getWinners($gid);
    if (!$winners) {
      clearState($tg_id);
      sendMessage($chat_id, "❌ No winners exist. Choose winners again.", adminKeyboard());
      echo "ok"; exit;
    }

    $lines = preg_split("/\r\n|\n|\r/", trim($text));
    $prizes = [];
    foreach ($lines as $ln) {
      $c = trim($ln);
      if ($c !== "") $prizes[] = $c;
    }

    if (count($prizes) < count($winners)) {
      sendMessage($chat_id, "❌ You must send <b>" . count($winners) . "</b> prize codes (one per winner). Try again:");
      echo "ok"; exit;
    }

    $sent = 0;
    for ($i = 0; $i < count($winners); $i++) {
      $tg = intval($winners[$i]["tg_id"]);
      $prize = htmlspecialchars($prizes[$i]);
      $msg = "🎉 Congratulations! You won the giveaway.\n\nYour prize code:\n<code>{$prize}</code>";
      $r = sendMessage($tg, $msg);
      if ($r && !empty($r["ok"])) $sent++;
    }

    clearState($tg_id);
    resetGiveaway();

    sendMessage($chat_id, "📨 Sent prize codes to <b>{$sent}</b> winners ✅\n\n🧹 Giveaway ended and reset.\nNow create new codes for next giveaway.", adminKeyboard());
    echo "ok"; exit;
  }

  // Fallback
  sendMessage($chat_id, "Use menu ✅", mainMenuKeyboard($isAdmin));
  echo "ok"; exit;
}

echo "ok";
