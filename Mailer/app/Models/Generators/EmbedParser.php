<?php
declare(strict_types=1);

namespace Remp\Mailer\Models\Generators;

class EmbedParser extends \Remp\MailerModule\Models\Generators\EmbedParser
{
    public function createEmbedMarkup(string $link, string $title, ?string $image = null, bool $isVideo = false): string
    {
        $html = "<br>";

        $html .= "<a href='{$link}' target='_blank' style='color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;text-decoration:none;text-align: center; display: block;width:100%;'>";

        if (!is_null($image)) {
            $html .= "<img src='{$image}' alt='{$title}' style='outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;max-width:100%;clear:both;display:inline;width:100%;height:auto;'>";
        } elseif (preg_match('/twitt/', $link)) {
            return "<br><a style=\"display: block;margin: 0 0 20px;padding: 7px 10px;text-decoration: none;text-align: center;font-weight: bold;font-family:'Helvetica Neue', Helvetica, Arial;color: #249fdc; background: #ffffff; border: 3px solid #249fdc;margin: 16px 0 16px 0\" href=\"{$link}\" target=\"_blank\">Zobraziť na Twitteri</a>";
        } else {
            $html .= "<span style='text-decoration: underline; color: #1F3F83;'>" . $link . "</span>";
        }

        if ($isVideo) {
            $html .= "<p style='color: #888;font-family: Arial,sans-serif;font-size: 14px;margin: 0; padding: 0;margin-top:5px;line-height: 1.3;text-align: left; text-decoration: none;'><i>{$this->videoLinkText}</i></p><br>";
        }

        return $html . "</a>" . PHP_EOL;
    }
}
