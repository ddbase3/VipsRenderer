<?php
declare(strict_types=1);

namespace VipsRenderer\Renderer;

use MediaFoundation\Api\IImageRenderer;
use MediaFoundation\Builder\ImageEdit;
use MediaFoundation\Exception\ImageRenderException;
use MediaFoundation\Exception\InvalidImageEditException;

/**
 * libvips CLI-based renderer.
 *
 * - Works on local file paths (no in-memory resources).
 * - Uses temporary intermediate files to compose a pipeline.
 * - Implements the edit operations exposed by MediaFoundation\Builder\ImageEdit.
 *
 * Notes:
 * - This implementation targets the libvips CLI available as `vips` and `vipsheader`.
 * - It assumes JPEG input is already "baked" (no RAW development).
 */
final class VipsImageRenderer implements IImageRenderer
{
	private string $vipsBin;
	private string $vipsHeaderBin;
	private string $tmpDir;

	public function __construct(
		string $tmpDir = '/tmp',
		string $vipsBin = '/usr/bin/vips',
		string $vipsHeaderBin = '/usr/bin/vipsheader',
	) {
		$this->tmpDir = rtrim($tmpDir, '/');
		$this->vipsBin = $vipsBin;
		$this->vipsHeaderBin = $vipsHeaderBin;
	}

	public function render(string $inputPath, ImageEdit $edit, string $outputPath): void
	{
		if (!is_file($inputPath)) {
			throw new ImageRenderException("Input file does not exist: {$inputPath}");
		}

		$spec = $edit->spec();

		$workFiles = [];
		$cur = $this->tmpFile('in', 'v', $workFiles);

		$this->run([$this->vipsBin, 'copy', $inputPath, $cur]);

		$cur = $this->applyRotateFlipCrop($cur, $spec, $workFiles);
		$cur = $this->applyResize($cur, $spec, $workFiles);
		$cur = $this->applyAdjustments($cur, $spec, $workFiles);
		$cur = $this->applyWatermark($cur, $spec, $workFiles);

		$this->export($cur, $spec, $outputPath);

		$this->cleanup($workFiles);
	}

	private function applyRotateFlipCrop(string $cur, array $spec, array &$workFiles): string
	{
		$t = $spec['transform'] ?? [];
		$rotate = (float)($t['rotate'] ?? 0.0);
		$flipH = (bool)($t['flip_h'] ?? false);
		$flipV = (bool)($t['flip_v'] ?? false);

		$rot = $this->normalizeRightAngle($rotate);
		if ($rot !== 0) {
			$out = $this->tmpFile('rot', 'v', $workFiles);
			$arg = match ($rot) {
				90  => 'd90',
				180 => 'd180',
				270 => 'd270',
				default => null,
			};
			if ($arg === null) {
				throw new InvalidImageEditException("Unsupported rotate angle for vips renderer: {$rotate}");
			}
			$this->run([$this->vipsBin, 'rot', $cur, $out, $arg]);
			$cur = $out;
		}

		if ($flipH) {
			$out = $this->tmpFile('flop', 'v', $workFiles);
			$this->run([$this->vipsBin, 'flop', $cur, $out]);
			$cur = $out;
		}
		if ($flipV) {
			$out = $this->tmpFile('flip', 'v', $workFiles);
			$this->run([$this->vipsBin, 'flip', $cur, $out]);
			$cur = $out;
		}

		$crop = $t['crop'] ?? null;
		$cropNorm = $t['crop_norm'] ?? null;

		if (is_array($crop)) {
			$x = (int)$crop['x']; $y = (int)$crop['y'];
			$w = (int)$crop['w']; $h = (int)$crop['h'];
			if ($w <= 0 || $h <= 0) {
				throw new InvalidImageEditException('Crop width/height must be > 0.');
			}

			$out = $this->tmpFile('crop', 'v', $workFiles);
			$this->run([$this->vipsBin, 'extract_area', $cur, $out, (string)$x, (string)$y, (string)$w, (string)$h]);
			$cur = $out;
		} elseif (is_array($cropNorm)) {
			$w0 = $this->headerInt($cur, 'width');
			$h0 = $this->headerInt($cur, 'height');

			$x = (int)round($this->clamp01((float)$cropNorm['x']) * $w0);
			$y = (int)round($this->clamp01((float)$cropNorm['y']) * $h0);
			$w = (int)round($this->clamp01((float)$cropNorm['w']) * $w0);
			$h = (int)round($this->clamp01((float)$cropNorm['h']) * $h0);

			$w = max(1, min($w, $w0 - $x));
			$h = max(1, min($h, $h0 - $y));

			$out = $this->tmpFile('cropn', 'v', $workFiles);
			$this->run([$this->vipsBin, 'extract_area', $cur, $out, (string)$x, (string)$y, (string)$w, (string)$h]);
			$cur = $out;
		}

		return $cur;
	}

	private function applyResize(string $cur, array $spec, array &$workFiles): string
	{
		$r = $spec['resize'] ?? null;
		if (!is_array($r)) {
			return $cur;
		}

		$mode = (string)($r['mode'] ?? 'fit');
		$maxW = $r['max_width'] ?? null;
		$maxH = $r['max_height'] ?? null;

		if ($maxW === null && $maxH === null) {
			return $cur;
		}

		$w0 = $this->headerInt($cur, 'width');
		$h0 = $this->headerInt($cur, 'height');

		if ($mode === 'fit') {
			$scaleW = ($maxW !== null) ? ((int)$maxW / $w0) : INF;
			$scaleH = ($maxH !== null) ? ((int)$maxH / $h0) : INF;
			$scale = min($scaleW, $scaleH, 1.0);

			if ($scale >= 1.0) {
				return $cur;
			}

			$out = $this->tmpFile('fit', 'v', $workFiles);
			$this->run([$this->vipsBin, 'resize', $cur, $out, $this->fmtFloat($scale)]);
			return $out;
		}

		if ($mode === 'fill') {
			if ($maxW === null || $maxH === null) {
				throw new InvalidImageEditException('resizeFill requires both width and height.');
			}
			$tw = (int)$maxW;
			$th = (int)$maxH;

			$scale = max($tw / $w0, $th / $h0);
			$scale = min($scale, 1e6);

			$resized = $this->tmpFile('fill_r', 'v', $workFiles);
			$this->run([$this->vipsBin, 'resize', $cur, $resized, $this->fmtFloat($scale)]);

			$w1 = $this->headerInt($resized, 'width');
			$h1 = $this->headerInt($resized, 'height');

			$x = (int)max(0, floor(($w1 - $tw) / 2));
			$y = (int)max(0, floor(($h1 - $th) / 2));

			$cropped = $this->tmpFile('fill_c', 'v', $workFiles);
			$this->run([$this->vipsBin, 'extract_area', $resized, $cropped, (string)$x, (string)$y, (string)$tw, (string)$th]);
			return $cropped;
		}

		if ($mode === 'stretch') {
			if ($maxW === null || $maxH === null) {
				throw new InvalidImageEditException('resize stretch requires both width and height.');
			}
			$tw = (int)$maxW;
			$th = (int)$maxH;

			$sx = $tw / $w0;
			$sy = $th / $h0;

			$out = $this->tmpFile('stretch', 'v', $workFiles);
			$this->run([$this->vipsBin, 'affine', $cur, $out, $this->fmtFloat($sx), '0', '0', $this->fmtFloat($sy)]);
			return $out;
		}

		throw new InvalidImageEditException("Unknown resize mode: {$mode}");
	}

	private function applyAdjustments(string $cur, array $spec, array &$workFiles): string
	{
		$a = $spec['adjust'] ?? [];
		if (!is_array($a)) {
			return $cur;
		}

		if (isset($a['_mood']) && is_array($a['_mood'])) {
			$mul = (float)($a['_mood']['mul'] ?? 1.0);
			$add = (float)($a['_mood']['add'] ?? 0.0);

			$out = $this->tmpFile('mood', 'v', $workFiles);
			$this->runLinear($cur, $out, $mul, $add);
			$cur = $out;
		}

		$exposure = (float)($a['exposure'] ?? 0.0);
		if ($exposure !== 0.0) {
			$mul = pow(2.0, $exposure);
			$out = $this->tmpFile('exp', 'v', $workFiles);
			$this->runLinear($cur, $out, $mul, 0.0);
			$cur = $out;
		}

		$contrast = (float)($a['contrast'] ?? 0.0);
		$brightness = (float)($a['brightness'] ?? 0.0);
		if ($contrast !== 0.0 || $brightness !== 0.0) {
			// contrast: moderate multiplicative range
			$mul = 1.0 + ($contrast * 0.5);

			// brightness: visible additive shift in 8-bit pixel space
			// -1..+1 -> -40..+40
			$add = $brightness * 40.0;

			$out = $this->tmpFile('lin', 'v', $workFiles);
			$this->runLinear($cur, $out, $mul, $add);
			$cur = $out;
		}

		$gamma = (float)($a['gamma'] ?? 1.0);
		if ($gamma !== 1.0) {
			if (!is_finite($gamma) || $gamma <= 0.0) {
				throw new InvalidImageEditException("Invalid gamma: {$gamma}");
			}
			$out = $this->tmpFile('gamma', 'v', $workFiles);
			$this->run([$this->vipsBin, 'gamma', $cur, $out, $this->fmtFloat($gamma)]);
			$cur = $out;
		}

		$unsupported = [
			'saturation', 'vibrance', 'highlights', 'shadows',
			'temperature', 'tint', 'clarity', 'sharpness',
		];
		foreach ($unsupported as $k) {
			$v = (float)($a[$k] ?? 0.0);
			if ($v !== 0.0) {
				throw new InvalidImageEditException("Adjustment '{$k}' is not implemented in VipsImageRenderer yet.");
			}
		}

		return $cur;
	}

	private function applyWatermark(string $cur, array $spec, array &$workFiles): string
	{
		$wm = $spec['watermark'] ?? null;
		if (!is_array($wm) || !($wm['enabled'] ?? false)) {
			return $cur;
		}

		$wmPath = (string)($wm['path'] ?? '');
		if ($wmPath === '' || !is_file($wmPath)) {
			throw new InvalidImageEditException("Watermark file does not exist: {$wmPath}");
		}

		$opacity = (float)($wm['opacity'] ?? 1.0);
		$scale = (float)($wm['scale'] ?? 1.0);
		$xOff = (int)($wm['x'] ?? 0);
		$yOff = (int)($wm['y'] ?? 0);
		$anchor = (string)($wm['anchor'] ?? 'se');

		$imgW = $this->headerInt($cur, 'width');
		$imgH = $this->headerInt($cur, 'height');

		$wmV = $this->tmpFile('wm_in', 'v', $workFiles);
		$this->run([$this->vipsBin, 'copy', $wmPath, $wmV]);

		if (!is_finite($scale) || $scale <= 0.0) {
			throw new InvalidImageEditException("Invalid watermark scale: {$scale}");
		}
		$wmW = $this->headerInt($wmV, 'width');
		$wmH = $this->headerInt($wmV, 'height');

		$targetW = (int)max(1, round($imgW * $scale));
		$factor = $targetW / max(1, $wmW);

		if (abs($factor - 1.0) > 0.001) {
			$wmScaled = $this->tmpFile('wm_s', 'v', $workFiles);
			$this->run([$this->vipsBin, 'resize', $wmV, $wmScaled, $this->fmtFloat($factor)]);
			$wmV = $wmScaled;
			$wmW = $this->headerInt($wmV, 'width');
			$wmH = $this->headerInt($wmV, 'height');
		}

		if ($opacity < 1.0) {
			if ($opacity < 0.0) {
				$opacity = 0.0;
			}
			$bands = $this->headerInt($wmV, 'bands');
			if ($bands >= 4) {
				$alpha = $this->tmpFile('wm_a', 'v', $workFiles);
				$this->run([$this->vipsBin, 'extract_band', $wmV, $alpha, '3']);

				$alpha2 = $this->tmpFile('wm_a2', 'v', $workFiles);
				$this->runLinear($alpha, $alpha2, $opacity, 0.0);

				$rgb = $this->tmpFile('wm_rgb', 'v', $workFiles);
				$this->run([$this->vipsBin, 'extract_band', $wmV, $rgb, '0', '--n', '3']);

				$rgba = $this->tmpFile('wm_rgba', 'v', $workFiles);
				$this->run([$this->vipsBin, 'bandjoin', $rgb, $alpha2, $rgba]);
				$wmV = $rgba;
			}
		}

		[$x, $y] = $this->anchorToXY($anchor, $imgW, $imgH, $wmW, $wmH, $xOff, $yOff);

		$out = $this->tmpFile('wm', 'v', $workFiles);
		$this->run([
			$this->vipsBin, 'composite2',
			$cur, $wmV, $out,
			'over',
			'--x', (string)$x,
			'--y', (string)$y,
		]);

		return $out;
	}

	private function export(string $cur, array $spec, string $outputPath): void
	{
		$e = $spec['export'] ?? [];
		if (!is_array($e)) {
			$e = [];
		}

		$format = strtolower((string)($e['format'] ?? 'jpeg'));
		$quality = (int)($e['quality'] ?? 90);
		$strip = (bool)($e['strip'] ?? true);
		$srgb = (bool)($e['srgb'] ?? true);

		$dir = dirname($outputPath);
		if (!is_dir($dir)) {
			if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
				throw new ImageRenderException("Failed to create output directory: {$dir}");
			}
		}

		if ($srgb) {
			$tmp = $outputPath . '.srgb.v';
			try {
				$this->run([$this->vipsBin, 'colourspace', $cur, $tmp, 'srgb']);
				$cur = $tmp;
			} catch (\Throwable $ignored) {
			}
		}

		$opts = [];
		if ($strip) {
			$opts[] = 'strip';
		}

		$suffix = match ($format) {
			'jpeg', 'jpg' => $this->buildSuffix('jpg', array_merge($opts, ["Q={$quality}"])),
			'webp'        => $this->buildSuffix('webp', array_merge($opts, ["Q={$quality}"])),
			'png'         => $this->buildSuffix('png', $opts),
			default       => throw new InvalidImageEditException("Unsupported export format: {$format}"),
		};

		$this->run([$this->vipsBin, 'copy', $cur, $outputPath . $suffix]);
	}

	private function runLinear(string $in, string $out, float $mul, float $add): void
	{
		if (!is_finite($mul) || !is_finite($add)) {
			throw new InvalidImageEditException('Linear parameters must be finite.');
		}

		if ($add < 0) {
			$this->run([$this->vipsBin, 'linear', $in, $out, $this->fmtFloat($mul), '--', $this->fmtFloat($add)]);
		} else {
			$this->run([$this->vipsBin, 'linear', $in, $out, $this->fmtFloat($mul), $this->fmtFloat($add)]);
		}
	}

	private function headerInt(string $path, string $field): int
	{
		$out = $this->runCapture([$this->vipsHeaderBin, '-f', $field, $path]);
		$out = trim($out);
		if (!preg_match('/^-?\d+$/', $out)) {
			throw new ImageRenderException("Failed to read vips header field '{$field}' from {$path}. Got: {$out}");
		}
		return (int)$out;
	}

	private function tmpFile(string $tag, string $ext, array &$workFiles): string
	{
		$file = $this->tmpDir . '/vips_' . $tag . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
		$workFiles[] = $file;
		return $file;
	}

	private function cleanup(array $files): void
	{
		foreach ($files as $f) {
			@unlink($f);
		}
	}

	private function run(array $cmd): void
	{
		$code = 0;
		$stderr = '';
		$stdout = $this->exec($cmd, $code, $stderr);
		if ($code !== 0) {
			$safe = implode(' ', array_map('escapeshellarg', $cmd));
			throw new ImageRenderException("Command failed ({$code}): {$safe}\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
		}
	}

	private function runCapture(array $cmd): string
	{
		$code = 0;
		$stderr = '';
		$stdout = $this->exec($cmd, $code, $stderr);
		if ($code !== 0) {
			$safe = implode(' ', array_map('escapeshellarg', $cmd));
			throw new ImageRenderException("Command failed ({$code}): {$safe}\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
		}
		return $stdout;
	}

	private function exec(array $cmd, int &$exitCode, string &$stderr): string {
		$escaped = implode(' ', array_map('escapeshellarg', $cmd));

		$out = [];
		$exitCode = 0;

		@exec($escaped . ' 2>&1', $out, $exitCode);

		$combined = implode("\n", $out);
		$stderr = $combined;

		return $combined;
	}

	private function execProcOpen(array $cmd, int &$exitCode, string &$stderr): string
	{
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$proc = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
		if (!is_resource($proc)) {
			throw new ImageRenderException('Failed to start process: ' . implode(' ', $cmd));
		}

		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]) ?: '';
		fclose($pipes[1]);

		$stderr = stream_get_contents($pipes[2]) ?: '';
		fclose($pipes[2]);

		$exitCode = proc_close($proc);
		return $stdout;
	}

	private function normalizeRightAngle(float $deg): int
	{
		$d = fmod($deg, 360.0);
		if ($d < 0) $d += 360.0;

		$candidates = [0, 90, 180, 270];
		$best = 0;
		$bestDiff = INF;
		foreach ($candidates as $c) {
			$diff = abs($d - $c);
			if ($diff < $bestDiff) {
				$bestDiff = $diff;
				$best = $c;
			}
		}
		return ($bestDiff <= 0.5) ? $best : (int)round($d);
	}

	private function clamp01(float $v): float
	{
		if (!is_finite($v)) return 0.0;
		return max(0.0, min(1.0, $v));
	}

	private function fmtFloat(float $v): string
	{
		if (!is_finite($v)) {
			throw new InvalidImageEditException('Float parameter must be finite.');
		}
		return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
	}

	private function buildSuffix(string $ext, array $opts): string
	{
		$opts = array_values(array_filter(array_map('trim', $opts), fn($s) => $s !== ''));
		if (count($opts) === 0) {
			return '';
		}
		return '[' . implode(',', $opts) . ']';
	}

	private function anchorToXY(string $anchor, int $imgW, int $imgH, int $wmW, int $wmH, int $xOff, int $yOff): array
	{
		$anchor = strtolower($anchor);

		$x = 0;
		$y = 0;

		if (str_contains($anchor, 'w')) {
			$x = 0;
		} elseif (str_contains($anchor, 'e')) {
			$x = $imgW - $wmW;
		} else {
			$x = (int)floor(($imgW - $wmW) / 2);
		}

		if (str_contains($anchor, 'n')) {
			$y = 0;
		} elseif (str_contains($anchor, 's')) {
			$y = $imgH - $wmH;
		} else {
			$y = (int)floor(($imgH - $wmH) / 2);
		}

		$x += $xOff;
		$y += $yOff;

		$x = max(0, min($x, max(0, $imgW - $wmW)));
		$y = max(0, min($y, max(0, $imgH - $wmH)));

		return [$x, $y];
	}
}
