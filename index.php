<?php
// ===================== CONFIG (ENV) =====================
$BOT_TOKEN = getenv("BOT_TOKEN");
$ADMIN_ID  = intval(getenv("ADMIN_ID"));

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

$FORCE_JOIN_1 = trim(getenv("FORCE_JOIN_1") ?: "");
$FORCE_JOIN_2 = trim(getenv("FORCE_JOIN_2") ?: "");

// Winner announcement channel (optional) e.g. @MyChannel or -100xxxx
$WINNER_ANNOUNCE_CHANNEL = trim(getenv("WINNER_ANNOUNCE_CHANNEL") ?: "");

// ===================== BASIC CHECKS =====================
if (!$BOT_TOKEN || !$ADMIN_ID || !$DB_HOST || !$DB_USER || !$DB_PASS) {
  http_response_code(200);
  echo "Missing ENV";
  exit;
}

// ===================== DB (PDO) =====================
try {
  $dsn = "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME}";
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch (Exception $e) {
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
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
  curl_setopt($ch, CURLOPT_TIMEOUT, 12);
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
  $res = tg("getChatMember", [
    "chat_id" => $channel,
    "user_id" => $user_id
  ]);
  if (!$res || empty($res["ok"])) return "left";
  return $res["result"]["status"] ?? "left";
}

function isJoined($channel, $user_id) {
  if (!$channel) return true;
  $st = getChatMemberStatus($channel, $user_id);
  return in_array($st, ["member", "administrator", "creator"]);
}

function mainMenuKeyboard($isAdmin = false) {
  $kb = [
    [["text"=>"🎁 Participate in Giveaway"]],
  ];
  if ($isAdmin) $kb[] = [["text"=>"🛠 Admin Panel"]];
  return [
    "keyboard" => $kb,
    "resize_keyboard" => true,
    "is_persistent" => true
  ];
}

function adminKeyboard() {
  return [
    "keyboard" => [
      [["text"=>"➕ Create Codes"], ["text"=>"🎲 Choose Winners"]],
      [["text"=>"👥 Participants Count"], ["text"=>"📨 Send Prize Codes"]],
      [["text"=>"🧹 Reset Giveaway"], ["text"=>"⬅️ Back"]]
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
    $buttons[] = [[ "text"=>"✅ Join Channel 1", "url"=>$url1 ]];
  }
  if ($FORCE_JOIN_2) {
    $url2 = (strpos($FORCE_JOIN_2, "@") === 0) ? "https://t.me/" . substr($FORCE_JOIN_2, 1) : "https://t.me/";
    $buttons[] = [[ "text"=>"✅ Join Channel 2", "url"=>$url2 ]];
  }

  $buttons[] = [[ "text"=>"✅ I've Joined (Check)", "callback_data"=>"check_join" ]];
  return ["inline_keyboard" => $buttons];
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
  $stmt->execute([":tg"=>$tg_id, ":st"=>$state, ":pl"=>$payload]);
}

function getState($tg_id) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT state, payload FROM bot_state WHERE tg_id=:tg");
  $stmt->execute([":tg"=>$tg_id]);
  return $stmt->fetch() ?: ["state"=>null, "payload"=>null];
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

function randomCode($len = 10) {
  $alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  $bytes = random_bytes($len);
  $out = "";
  $n = strlen($alphabet);
  for ($i=0; $i<$len; $i++) {
    $out .= $alphabet[ord($bytes[$i]) % $n];
  }
  return $out;
}

function createCodes($giveaway_id, $count) {
  global $pdo;
  $count = max(1, min(500, intval($count)));
  $created = 0;

  $stmt = $pdo->prepare("INSERT INTO giveaway_codes (giveaway_id, code) VALUES (:gid, :code)");
  while ($created < $count) {
    $code = randomCode(10);
    try {
      $stmt->execute([":gid"=>$giveaway_id, ":code"=>$code]);
      $created++;
    } catch (Exception $e) {
      // collision -> retry
    }
  }
  return $created;
}

function joinWithCode($tg_id, $tg_name, $code) {
  global $pdo;
  $gid = getActiveGiveawayId();

  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare("SELECT id, is_used FROM giveaway_codes WHERE giveaway_id=:gid AND code=:c FOR UPDATE");
    $stmt->execute([":gid"=>$gid, ":c"=>$code]);
    $row = $stmt->fetch();

    if (!$row) { $pdo->rollBack(); return ["ok"=>false, "msg"=>"❌ Invalid code."]; }
    if ($row["is_used"]) { $pdo->rollBack(); return ["ok"=>false, "msg"=>"❌ Code already used."]; }

    $stmt = $pdo->prepare("UPDATE giveaway_codes SET is_used=true, used_by=:tg, used_at=now() WHERE id=:id");
    $stmt->execute([":tg"=>$tg_id, ":id"=>$row["id"]]);

    $stmt = $pdo->prepare("
      INSERT INTO giveaway_participants (giveaway_id, tg_id, tg_name)
      VALUES (:gid, :tg, :nm)
      ON CONFLICT (giveaway_id, tg_id) DO NOTHING
    ");
    $stmt->execute([":gid"=>$gid, ":tg"=>$tg_id, ":nm"=>$tg_name]);

    $pdo->commit();
    return ["ok"=>true, "msg"=>"✅ You are entered in the giveaway!"];
  } catch (Exception $e) {
    $pdo->rollBack();
    return ["ok"=>false, "msg"=>"❌ Error. Try again."];
  }
}

function pickWinners($giveaway_id, $count) {
  global $pdo;
  $count = max(1, min(200, intval($count)));

  $stmt = $pdo->prepare("SELECT tg_id, tg_name FROM giveaway_participants WHERE giveaway_id=:gid");
  $stmt->execute([":gid"=>$giveaway_id]);
  $all = $stmt->fetchAll();
  if (!$all) return ["ok"=>false, "msg"=>"No participants yet."];

  shuffle($all);
  $picked = array_slice($all, 0, min($count, count($all)));

  $pdo->beginTransaction();
  try {
    $pdo->prepare("DELETE FROM giveaway_winners WHERE giveaway_id=:gid")->execute([":gid"=>$giveaway_id]);

    $ins = $pdo->prepare("INSERT INTO giveaway_winners (giveaway_id, tg_id, tg_name) VALUES (:gid, :tg, :nm)");
    foreach ($picked as $p) {
      $ins->execute([":gid"=>$giveaway_id, ":tg"=>$p["tg_id"], ":nm"=>$p["tg_name"]]);
    }
    $pdo->commit();
    return ["ok"=>true, "picked"=>$picked];
  } catch (Exception $e) {
    $pdo->rollBack();
    return ["ok"=>false, "msg"=>"Error choosing winners."];
  }
}

function getWinners($giveaway_id) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT tg_id, tg_name FROM giveaway_winners WHERE giveaway_id=:gid ORDER BY id ASC");
  $stmt->execute([":gid"=>$giveaway_id]);
  return $stmt->fetchAll();
}

function getParticipantsCount($giveaway_id) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM giveaway_participants WHERE giveaway_id=:gid");
  $stmt->execute([":gid"=>$giveaway_id]);
  $row = $stmt->fetch();
  return $row ? intval($row["c"]) : 0;
}

function resetGiveaway() {
  global $pdo;
  $gid = getActiveGiveawayId();
  if (!$gid) return;

  $pdo->prepare("UPDATE giveaways SET status='ended', ended_at=now() WHERE id=:gid")->execute([":gid"=>$gid]);
  $pdo->exec("INSERT INTO giveaways (status) VALUES ('active')");
}

function requireJoinOrPrompt($chat_id, $tg_id, $isAdmin) {
  global $FORCE_JOIN_1, $FORCE_JOIN_2;

  $ok1 = isJoined($FORCE_JOIN_1, $tg_id);
  $ok2 = isJoined($FORCE_JOIN_2, $tg_id);

  if ($ok1 && $ok2) {
    sendMessage($chat_id, "✅ Verified!\n\nUse menu to participate giveaway.", mainMenuKeyboard($isAdmin));
    return true;
  }

  sendMessage(
    $chat_id,
    "⚠️ Please join both channels first, then tap <b>I've Joined (Check)</b>.",
    forceJoinMarkup()
  );
  return false;
}

// Winner announcement: NAME ONLY (no IDs)
function announceWinnersToChannel($giveaway_id, $picked) {
  global $WINNER_ANNOUNCE_CHANNEL;
  if (!$WINNER_ANNOUNCE_CHANNEL) return;

  $count = count($picked);
  $text = "🎉 <b>Giveaway Winners Announced</b>\n\n";

  $i = 1;
  foreach ($picked as $p) {
    $name = trim($p["tg_name"] ?? "");
    if ($name === "") $name = "Winner #{$i}";
    $name = htmlspecialchars($name);

    $text .= "{$i}) {$name}\n";
    $i++;
    if ($i > 200) break;
  }

  $text .= "\n✅ Total winners: <b>{$count}</b>";
  sendMessage($WINNER_ANNOUNCE_CHANNEL, $text);
}

// ===================== UPDATE HANDLER =====================
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { echo "ok"; exit; }

$message = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;

if ($callback) {
  $cb_id = $callback["id"];
  $from = $callback["from"];
  $tg_id = intval($from["id"]);
  $chat_id = intval($callback["message"]["chat"]["id"]);
  $data = $callback["data"] ?? "";

  $isAdmin = ($tg_id === $GLOBALS["ADMIN_ID"]);

  if ($data === "check_join") {
    answerCb($cb_id, "Checking...");
    requireJoinOrPrompt($chat_id, $tg_id, $isAdmin);
    echo "ok"; exit;
  }

  answerCb($cb_id);
  echo "ok"; exit;
}

if ($message) {
  $chat_id = intval($message["chat"]["id"]);
  $from = $message["from"];
  $tg_id = intval($from["id"]);
  $first = trim($from["first_name"] ?? "");
  $username = trim($from["username"] ?? "");
  $tg_name = trim($first . ($username ? " (@$username)" : ""));

  $text = trim($message["text"] ?? "");
  $isAdmin = ($tg_id === $ADMIN_ID);

  if ($text === "/start") {
    requireJoinOrPrompt($chat_id, $tg_id, $isAdmin);
    echo "ok"; exit;
  }

  if ($isAdmin && $text === "🛠 Admin Panel") {
    clearState($tg_id);
    sendMessage($chat_id, "🛠 <b>Admin Panel</b>", adminKeyboard());
    echo "ok"; exit;
  }

  if ($text === "⬅️ Back") {
    clearState($tg_id);
    sendMessage($chat_id, "Main menu ✅", mainMenuKeyboard($isAdmin));
    echo "ok"; exit;
  }

  if ($text === "🎁 Participate in Giveaway") {
    if (!requireJoinOrPrompt($chat_id, $tg_id, $isAdmin)) { echo "ok"; exit; }
    setState($tg_id, "await_code", "");
    sendMessage($chat_id, "🎁 Enter the <b>unique code</b> to participate in giveaway:");
    echo "ok"; exit;
  }

  // Admin buttons
  if ($isAdmin && $text === "➕ Create Codes") {
    $gid = getActiveGiveawayId();
    setState($tg_id, "admin_create_codes", (string)$gid);
    sendMessage($chat_id, "➕ How many unique codes to create? (1 - 500)");
    echo "ok"; exit;
  }

  if ($isAdmin && $text === "🎲 Choose Winners") {
    $gid = getActiveGiveawayId();
    setState($tg_id, "admin_choose_winners", (string)$gid);
    sendMessage($chat_id, "🎲 How many winners you want? (example: 3)");
    echo "ok"; exit;
  }

  if ($isAdmin && $text === "👥 Participants Count") {
    $gid = getActiveGiveawayId();
    $count = getParticipantsCount($gid);
    sendMessage($chat_id, "👥 Participants in current giveaway: <b>{$count}</b>", adminKeyboard());
    echo "ok"; exit;
  }

  if ($isAdmin && $text === "📨 Send Prize Codes") {
    $gid = getActiveGiveawayId();
    $w = getWinners($gid);
    if (!$w) {
      sendMessage($chat_id, "❌ No winners chosen yet. First use: 🎲 Choose Winners", adminKeyboard());
      echo "ok"; exit;
    }
    setState($tg_id, "admin_send_prizes", (string)$gid);
    $n = count($w);
    sendMessage(
      $chat_id,
      "📨 Send <b>{$n}</b> prize codes now.\n\nSend as:\n<code>CODE1\nCODE2\nCODE3...</code>\n(one per winner, in same order shown)"
    );
    echo "ok"; exit;
  }

  if ($isAdmin && $text === "🧹 Reset Giveaway") {
    resetGiveaway();
    clearState($tg_id);
    sendMessage($chat_id, "🧹 Giveaway reset ✅\nNew giveaway started.\nNow create new unique codes.", adminKeyboard());
    echo "ok"; exit;
  }

  // State machine
  $st = getState($tg_id);
  $state = $st["state"];

  if ($state === "await_code") {
    if (!requireJoinOrPrompt($chat_id, $tg_id, $isAdmin)) { echo "ok"; exit; }

    $code = strtoupper(preg_replace("/\s+/", "", $text));
    if (strlen($code) < 6) {
      sendMessage($chat_id, "❌ Invalid code format. Try again:");
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
    if ($num < 1 || $num > 500) {
      sendMessage($chat_id, "❌ Enter a number between 1 and 500:");
      echo "ok"; exit;
    }
    $made = createCodes($gid, $num);
    clearState($tg_id);
    sendMessage($chat_id, "✅ Created <b>{$made}</b> unique codes for current giveaway.", adminKeyboard());
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
      sendMessage($chat_id, "❌ ".$pick["msg"], adminKeyboard());
      echo "ok"; exit;
    }

    $picked = $pick["picked"];

    // auto announce to channel (NAME ONLY)
    announceWinnersToChannel($gid, $picked);

    // admin list (NAME ONLY)
    $out = "🎉 <b>Winners Chosen</b>\n\n";
    $i=1;
    foreach ($picked as $p) {
      $name = trim($p["tg_name"] ?? "");
      if ($name === "") $name = "Winner #{$i}";
      $name = htmlspecialchars($name);
      $out .= "{$i}) {$name}\n";
      $i++;
    }

    if ($GLOBALS["WINNER_ANNOUNCE_CHANNEL"]) {
      $out .= "\n📣 Announced in: <code>".htmlspecialchars($GLOBALS["WINNER_ANNOUNCE_CHANNEL"])."</code>";
    } else {
      $out .= "\n📣 Winner announce channel not set (ENV WINNER_ANNOUNCE_CHANNEL).";
    }

    $out .= "\n\nNow tap: 📨 Send Prize Codes";
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
    $codes = [];
    foreach ($lines as $ln) {
      $c = trim($ln);
      if ($c !== "") $codes[] = $c;
    }

    if (count($codes) < count($winners)) {
      sendMessage($chat_id, "❌ You must send <b>".count($winners)."</b> codes (one per winner). Try again:");
      echo "ok"; exit;
    }

    $sent = 0;
    for ($i=0; $i<count($winners); $i++) {
      $tg = intval($winners[$i]["tg_id"]);
      $code = htmlspecialchars($codes[$i]);
      $msg = "🎉 Congratulations! You won the giveaway.\n\nYour prize code:\n<code>{$code}</code>";
      $r = sendMessage($tg, $msg);
      if ($r && !empty($r["ok"])) $sent++;
    }

    clearState($tg_id);

    // auto reset after sending prizes
    resetGiveaway();

    sendMessage(
      $chat_id,
      "📨 Sent prize codes to <b>{$sent}</b> winners ✅\n\n🧹 Giveaway ended and reset.\nCreate new unique codes for next giveaway.",
      adminKeyboard()
    );
    echo "ok"; exit;
  }

  // Default
  if (!requireJoinOrPrompt($chat_id, $tg_id, $isAdmin)) { echo "ok"; exit; }
  sendMessage($chat_id, "Use menu to participate giveaway ✅", mainMenuKeyboard($isAdmin));
  echo "ok"; exit;
}

echo "ok";
