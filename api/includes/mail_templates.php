<?php
/**
 * Универсальный HTML-шаблон для всех писем системы.
 *
 * Использование:
 *   require_once __DIR__ . '/mail_templates.php';
 *   $html = renderMailHtml([
 *     'title'   => 'Сброс пароля',
 *     'preview' => 'Ссылка для сброса действительна 30 минут',
 *     'intro'   => 'Здравствуйте, Алексей!',
 *     'body'    => '<p>Был получен запрос на сброс пароля...</p>',
 *     'cta'     => ['text' => 'Сбросить пароль', 'url' => 'https://...'],
 *     'footer'  => 'Если вы не запрашивали — проигнорируйте письмо.',
 *   ]);
 *
 * Особенности:
 *   - Inline-стили (большинство почтовых клиентов не понимают <style>)
 *   - Table-based layout для совместимости с Outlook
 *   - Адаптивный (responsive) под мобильные через медиа-запросы
 *   - Логотип берётся как абсолютный URL с SITE_URL
 *   - Цветовая палитра бренда: #E76F51 / #F4A261 / #FFD54F / #502314
 */

if (!function_exists('renderMailHtml')) {
    /**
     * @param array $opts {
     *   title:    string,        обязательный — заголовок над текстом
     *   preview?: string,        короткий текст, отображается в превью почты
     *   intro?:   string,        приветствие («Здравствуйте, Имя!»)
     *   body:     string,        основное содержимое (HTML)
     *   cta?:     array{text:string,url:string},  кнопка-призыв
     *   footer?:  string,        нижний текст (мелким шрифтом)
     * }
     */
    function renderMailHtml(array $opts): string {
        $siteUrl = rtrim($_ENV['SITE_URL'] ?? 'https://supply-department.online', '/');
        $logoUrl = $siteUrl . '/pwa-192x192.png';

        $title   = htmlspecialchars($opts['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $preview = htmlspecialchars($opts['preview'] ?? '', ENT_QUOTES, 'UTF-8');
        $intro   = $opts['intro'] ?? '';
        $body    = $opts['body'] ?? '';
        $footer  = $opts['footer'] ?? '';
        $cta     = $opts['cta'] ?? null;

        $ctaHtml = '';
        if ($cta && !empty($cta['url']) && !empty($cta['text'])) {
            $url  = htmlspecialchars($cta['url'], ENT_QUOTES, 'UTF-8');
            $text = htmlspecialchars($cta['text'], ENT_QUOTES, 'UTF-8');
            $ctaHtml = <<<HTML
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:32px auto 0;">
              <tr>
                <td align="center" bgcolor="#E76F51" style="border-radius:10px;">
                  <a href="{$url}" target="_blank" style="display:inline-block;padding:14px 32px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:16px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:10px;">
                    {$text}
                  </a>
                </td>
              </tr>
            </table>
HTML;
        }

        $introHtml = '';
        if ($intro !== '') {
            $introSafe = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');
            $introHtml = "<p style=\"margin:0 0 16px;font-size:16px;color:#333333;font-weight:600;\">{$introSafe}</p>";
        }

        $footerHtml = '';
        if ($footer !== '') {
            $footerSafe = htmlspecialchars($footer, ENT_QUOTES, 'UTF-8');
            $footerHtml = "<p style=\"margin:24px 0 0;font-size:13px;color:#888888;line-height:1.6;\">{$footerSafe}</p>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>{$title}</title>
<style>
  @media (max-width: 600px) {
    .mail-card { width: 100% !important; border-radius: 0 !important; }
    .mail-inner { padding: 24px 20px !important; }
    .mail-title { font-size: 22px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f5f3f0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;font-size:1px;line-height:1px;color:#f5f3f0;opacity:0;">{$preview}</div>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f5f3f0;">
  <tr>
    <td align="center" style="padding:32px 12px;">

      <table role="presentation" class="mail-card" width="600" cellspacing="0" cellpadding="0" border="0" style="background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 16px rgba(80,35,20,0.08);">

        <tr>
          <td style="background:linear-gradient(135deg,#E76F51 0%,#F4A261 100%);padding:28px 32px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td valign="middle" width="56">
                  <img src="{$logoUrl}" width="48" height="48" alt="Supply Department" style="display:block;border-radius:12px;border:0;">
                </td>
                <td valign="middle" style="padding-left:14px;">
                  <div style="font-size:18px;font-weight:700;color:#ffffff;letter-spacing:-0.2px;">Supply Department</div>
                  <div style="font-size:13px;color:rgba(255,255,255,0.85);margin-top:2px;">Портал закупок</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td class="mail-inner" style="padding:36px 40px;">
            <h1 class="mail-title" style="margin:0 0 20px;font-size:26px;font-weight:700;color:#502314;line-height:1.3;letter-spacing:-0.3px;">{$title}</h1>
            {$introHtml}
            <div style="font-size:15px;color:#444444;line-height:1.65;">
              {$body}
            </div>
            {$ctaHtml}
            {$footerHtml}
          </td>
        </tr>

        <tr>
          <td style="background-color:#fafaf8;padding:20px 32px;border-top:1px solid #ececec;">
            <p style="margin:0;font-size:12px;color:#999999;line-height:1.5;text-align:center;">
              Это автоматическое письмо от системы Supply Department.<br>
              На него не нужно отвечать.
            </p>
          </td>
        </tr>

      </table>

    </td>
  </tr>
</table>
</body>
</html>
HTML;
    }
}
