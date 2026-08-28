<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Support;

use chillerlan\QRCode\Common\Mode;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\QRCode as ChillerlanQRCode;

/**
 * Renders a scannable QR code in the terminal using half-block characters
 * (▀ ▄ █ ' '), 2 matrix rows per text line.
 *
 * QR encoding itself is delegated to the stable chillerlan/php-qrcode
 * library (composer require chillerlan/php-qrcode) — do not hand-roll
 * Reed-Solomon. When the library is absent, callers get a null and should
 * print the raw URL instead of a fake, unscannable matrix.
 */
final class TerminalQr
{
    /**
     * Renders $text as a scannable terminal QR code, or returns null when
     * chillerlan/php-qrcode is not installed.
     */
    public static function render(string $text): ?string
    {
        if (!class_exists(ChillerlanQRCode::class)) {
            return null;
        }

        $qr = new ChillerlanQRCode();
        foreach (Mode::INTERFACES as $dataInterface) {
            if ($dataInterface::validateString($text)) {
                $qr->addSegment(new $dataInterface($text));
                break;
            }
        }
        $grid = $qr->getQRMatrix()->getMatrix();
        $rows = count($grid);
        $cols = $rows > 0 ? count($grid[0]) : 0;
        if ($rows === 0 || $cols === 0) {
            return null;
        }
        $dark = static fn (int $v): bool => ($v & QRMatrix::IS_DARK) === QRMatrix::IS_DARK;

        $out = "\n" . str_repeat(' ', $cols + 4) . "\n";
        for ($y = 0; $y < $rows; $y += 2) {
            $line = '  ';
            for ($x = 0; $x < $cols; $x++) {
                $top = $dark($grid[$y][$x]);
                $bottom = isset($grid[$y + 1]) && $dark($grid[$y + 1][$x]);
                $line .= match (true) {
                    $top && $bottom => '█',
                    $top => '▀',
                    $bottom => '▄',
                    default => ' ',
                };
            }
            $out .= $line . "  \n";
        }
        return $out;
    }

    /**
     * Renders the QR when possible (URL always included as fallback for
     * devices that cannot scan a terminal), or just the URL when the
     * library is absent.
     */
    public static function renderOrUrl(string $text): string
    {
        $qr = self::render($text);
        $urlBlock = "Open from any device already signed into Telegram:\n  {$text}\n";
        if ($qr !== null) {
            return $qr . "\n" . $urlBlock . "Scan: Telegram → Settings → Devices → Link Desktop Device\n";
        }
        return "\n(Install chillerlan/php-qrcode for a scannable terminal QR code.)\n" . $urlBlock;
    }
}
