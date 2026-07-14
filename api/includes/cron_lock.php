<?php
/**
 * Блокировка одиночного запуска cron-скрипта — с самоочисткой.
 *
 * Проблема, ради которой это написано: 03.07.2026 два почтовых крона
 * (email_import и tit_replies) зависли на мёртвом IMAP-соединении и держали
 * flock 11 дней. Каждый следующий запуск видел занятый лок, писал
 * «Already running» и выходил — почта не читалась вообще.
 *
 * Здесь лок хранит PID и время старта владельца. Если владелец жив дольше
 * $staleAfter секунд — он считается зависшим, его снимают (SIGTERM, затем
 * SIGKILL) и лок забирают себе.
 *
 * Важно: файл открывается в режиме 'c+', а не 'w' — 'w' обрезает файл ещё
 * до попытки flock, и содержимое (PID владельца) теряется.
 */

/**
 * Захватить лок. Возвращает:
 *   ['fp' => resource, 'killed_pid' => ?int]  — лок наш
 *   ['fp' => false,    'killed_pid' => null]  — занят живым процессом, надо выйти
 *
 * @param int $staleAfter через сколько секунд владелец считается зависшим
 */
function cronAcquireLock(string $lockFile, int $staleAfter = 600): array
{
    $fp = fopen($lockFile, 'c+');
    if (!$fp) return ['fp' => false, 'killed_pid' => null];

    if (flock($fp, LOCK_EX | LOCK_NB)) {
        cronLockWriteOwner($fp);
        return ['fp' => $fp, 'killed_pid' => null];
    }

    // Лок занят. Смотрим, кем и как давно.
    $owner = cronLockReadOwner($fp);
    $pid   = $owner['pid'];
    $age   = $owner['started'] ? (time() - $owner['started']) : null;

    // Владелец неизвестен или ещё не выработал свой лимит — уступаем.
    if (!$pid || $age === null || $age < $staleAfter) {
        fclose($fp);
        return ['fp' => false, 'killed_pid' => null];
    }

    // Владелец жив дольше лимита — считаем зависшим и снимаем.
    if (!posix_kill($pid, 0)) {
        // Процесса уже нет, а лок почему-то занят — редкий случай, просто уступаем.
        fclose($fp);
        return ['fp' => false, 'killed_pid' => null];
    }
    posix_kill($pid, SIGTERM);
    for ($i = 0; $i < 10 && posix_kill($pid, 0); $i++) usleep(300000);
    if (posix_kill($pid, 0)) posix_kill($pid, SIGKILL);
    for ($i = 0; $i < 10 && posix_kill($pid, 0); $i++) usleep(300000);

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return ['fp' => false, 'killed_pid' => null];
    }
    cronLockWriteOwner($fp);
    return ['fp' => $fp, 'killed_pid' => $pid];
}

function cronLockWriteOwner($fp): void
{
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(['pid' => getmypid(), 'started' => time()]));
    fflush($fp);
}

/** @return array{pid: ?int, started: ?int} */
function cronLockReadOwner($fp): array
{
    rewind($fp);
    $raw = stream_get_contents($fp);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) return ['pid' => null, 'started' => null];
    $pid = isset($data['pid']) ? (int)$data['pid'] : 0;
    $st  = isset($data['started']) ? (int)$data['started'] : 0;
    return ['pid' => $pid ?: null, 'started' => $st ?: null];
}

/**
 * Таймауты IMAP. Без них c-client ждёт ответа сервера бесконечно — именно
 * так крон и завис. set_time_limit() тут не помогает: он не прерывает
 * блокирующее чтение сокета.
 */
function cronImapTimeouts(int $open = 20, int $rw = 30): void
{
    imap_timeout(IMAP_OPENTIMEOUT, $open);
    imap_timeout(IMAP_READTIMEOUT, $rw);
    imap_timeout(IMAP_WRITETIMEOUT, $rw);
    imap_timeout(IMAP_CLOSETIMEOUT, 15);
}
