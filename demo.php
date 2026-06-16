<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * vtf.php — Pure PHP VTF renderer (no external tools, no extensions beyond GD)
 *
 * Usage:
 *   serve_vtf('/path/to/texture.vtf');          // outputs PNG to browser
 *   $img = vtf_to_gd('/path/to/texture.vtf');   // returns GD image resource
 *
 * Supported image formats inside VTF:
 *   RGBA8888, ABGR8888, RGB888, BGR888, BGRA8888, ARGB8888, BGRX8888,
 *   RGB565, BGR565, BGRA4444, BGRA5551, BGRX5551,
 *   A8, I8, IA88,
 *   DXT1 (BC1), DXT3 (BC2), DXT5 (BC3)
 *
 * VTF header spec: https://developer.valvesoftware.com/wiki/Valve_Texture_Format
 */

// ─── Format constants ────────────────────────────────────────────────────────
const VTF_FMT_RGBA8888        = 0;
const VTF_FMT_ABGR8888        = 1;
const VTF_FMT_RGB888          = 2;
const VTF_FMT_BGR888          = 3;
const VTF_FMT_RGB565          = 4;
const VTF_FMT_I8              = 5;
const VTF_FMT_IA88            = 6;
const VTF_FMT_P8              = 7;  // unsupported (palette)
const VTF_FMT_A8              = 8;
const VTF_FMT_RGB888_BLUE     = 9;
const VTF_FMT_BGR888_BLUE     = 10;
const VTF_FMT_ARGB8888        = 11;
const VTF_FMT_BGRA8888        = 12;
const VTF_FMT_DXT1            = 13;
const VTF_FMT_DXT3            = 14;
const VTF_FMT_DXT5            = 15;
const VTF_FMT_BGRX8888        = 16;
const VTF_FMT_BGR565          = 17;
const VTF_FMT_BGRX5551        = 18;
const VTF_FMT_BGRA4444        = 19;
const VTF_FMT_DXT1_ALPHA      = 20;
const VTF_FMT_BGRA5551        = 21;

// ─── Public API ──────────────────────────────────────────────────────────────

/**
 * Serve a VTF file directly to the browser as a PNG.
 */
function serve_vtf(string $path): void {
    $img = vtf_to_gd($path);
    header('Content-Type: image/png');
    imagepng($img);
    imagedestroy($img);
}

/**
 * Parse a VTF file and return a GD image resource of the highest-resolution mipmap.
 * Returns false on failure.
 */
function vtf_to_gd(string $path) {
    $data = file_get_contents($path);
    if ($data === false) throw new RuntimeException("Cannot read file: $path");

    // ── Header ──────────────────────────────────────────────────────────────
    // Magic: "VTF\0"
    if (substr($data, 0, 4) !== "VTF\0") {
        throw new RuntimeException("Not a VTF file: bad magic");
    }

    $versionMaj = _u32($data, 4);
    $versionMin = _u32($data, 8);
    $headerSize = _u32($data, 12);
    $width       = _u16($data, 16);
    $height      = _u16($data, 18);
    // flags        = u32 @ 20
    $numFrames   = _u16($data, 24);
    // firstFrame   = u16 @ 26
    // padding[4]   @ 28
    // reflectivity[12] @ 32 (3× float)
    // padding[4]   @ 44
    // bumpScale    @ 48 (float)
    $imageFormat = _s32($data, 52);   // signed — -1 means NONE
    $numMipmaps  = ord($data[56]);
    $thumbFormat = _s32($data, 57);
    $thumbWidth  = ord($data[61]);
    $thumbHeight = ord($data[62]);

    // v7.2+ has depth field
    $depth = 1;
    if ($versionMaj > 7 || ($versionMaj === 7 && $versionMin >= 2)) {
        $depth = _u16($data, 63);
        if ($depth < 1) $depth = 1;
    }

    // ── Locate image data ────────────────────────────────────────────────────
    // Data layout after the header (padded to headerSize):
    //   1) Low-res thumbnail
    //   2) Mipmaps from smallest (mip N-1) to largest (mip 0), for each frame/face/slice
    //
    // We skip the thumbnail and all mipmaps except mip 0.

    $thumbBytes = _fmt_bytes($thumbFormat, max(1,$thumbWidth), max(1,$thumbHeight));
    $offset = $headerSize + $thumbBytes;

    // Skip mipmaps from smallest to second-largest (indices numMipmaps-1 … 1)
    // Mip sizes: mip k → max(1, w>>k) × max(1, h>>k)
    for ($mip = $numMipmaps - 1; $mip >= 1; $mip--) {
        $mw = max(1, $width  >> $mip);
        $mh = max(1, $height >> $mip);
        $offset += _fmt_bytes($imageFormat, $mw, $mh) * $numFrames * $depth;
    }

    // Mip 0 = full resolution, first frame
    $imgBytes = _fmt_bytes($imageFormat, $width, $height);
    $imgData  = substr($data, $offset, $imgBytes);

    if (strlen($imgData) < $imgBytes) {
        throw new RuntimeException("VTF data truncated (need $imgBytes bytes at offset $offset, got " . strlen($imgData) . ")");
    }

    // ── Decode ───────────────────────────────────────────────────────────────
    return _decode($imgData, $width, $height, $imageFormat);
}

// ─── Internal helpers ─────────────────────────────────────────────────────────

function _u16(string $d, int $o): int {
    return unpack('v', substr($d,$o,2))[1];
}
function _u32(string $d, int $o): int {
    return unpack('V', substr($d,$o,4))[1];
}
function _s32(string $d, int $o): int {
    $v = unpack('V', substr($d,$o,4))[1];
    if ($v >= 0x80000000) $v -= 0x100000000;
    return $v;
}

/**
 * Byte size of image data for a given format and dimensions.
 */
function _fmt_bytes(int $fmt, int $w, int $h): int {
    // DXT formats: 4×4 blocks
    $bw = max(1, (int)ceil($w/4));
    $bh = max(1, (int)ceil($h/4));
    switch ($fmt) {
        case VTF_FMT_DXT1:
        case VTF_FMT_DXT1_ALPHA: return $bw * $bh * 8;
        case VTF_FMT_DXT3:
        case VTF_FMT_DXT5:       return $bw * $bh * 16;
        case VTF_FMT_RGBA8888:
        case VTF_FMT_ABGR8888:
        case VTF_FMT_ARGB8888:
        case VTF_FMT_BGRA8888:
        case VTF_FMT_BGRX8888:   return $w * $h * 4;
        case VTF_FMT_RGB888:
        case VTF_FMT_BGR888:
        case VTF_FMT_RGB888_BLUE:
        case VTF_FMT_BGR888_BLUE: return $w * $h * 3;
        case VTF_FMT_RGB565:
        case VTF_FMT_BGR565:
        case VTF_FMT_BGRA4444:
        case VTF_FMT_BGRX5551:
        case VTF_FMT_BGRA5551:
        case VTF_FMT_IA88:        return $w * $h * 2;
        case VTF_FMT_I8:
        case VTF_FMT_A8:          return $w * $h;
        default:                  return $w * $h * 4; // safe fallback
    }
}

/**
 * Decode raw VTF image bytes into a GD truecolor image.
 */
function _decode(string $raw, int $w, int $h, int $fmt) {
    $img = imagecreatetruecolor($w, $h);
    imagesavealpha($img, true);
    imagealphablending($img, false);

    switch ($fmt) {
        case VTF_FMT_RGBA8888:    _dec_rgba8888($img, $raw, $w, $h, 'RGBA'); break;
        case VTF_FMT_ABGR8888:    _dec_rgba8888($img, $raw, $w, $h, 'ABGR'); break;
        case VTF_FMT_ARGB8888:    _dec_rgba8888($img, $raw, $w, $h, 'ARGB'); break;
        case VTF_FMT_BGRA8888:    _dec_rgba8888($img, $raw, $w, $h, 'BGRA'); break;
        case VTF_FMT_BGRX8888:    _dec_rgba8888($img, $raw, $w, $h, 'BGRX'); break;
        case VTF_FMT_RGB888:
        case VTF_FMT_RGB888_BLUE: _dec_rgb888($img, $raw, $w, $h, false); break;
        case VTF_FMT_BGR888:
        case VTF_FMT_BGR888_BLUE: _dec_rgb888($img, $raw, $w, $h, true); break;
        case VTF_FMT_RGB565:      _dec_rgb565($img, $raw, $w, $h, false); break;
        case VTF_FMT_BGR565:      _dec_rgb565($img, $raw, $w, $h, true); break;
        case VTF_FMT_BGRA4444:    _dec_bgra4444($img, $raw, $w, $h); break;
        case VTF_FMT_BGRX5551:    _dec_bgra5551($img, $raw, $w, $h, false); break;
        case VTF_FMT_BGRA5551:    _dec_bgra5551($img, $raw, $w, $h, true); break;
        case VTF_FMT_I8:          _dec_i8($img, $raw, $w, $h); break;
        case VTF_FMT_IA88:        _dec_ia88($img, $raw, $w, $h); break;
        case VTF_FMT_A8:          _dec_a8($img, $raw, $w, $h); break;
        case VTF_FMT_DXT1:        _dec_dxt1($img, $raw, $w, $h, false); break;
        case VTF_FMT_DXT1_ALPHA:  _dec_dxt1($img, $raw, $w, $h, true); break;
        case VTF_FMT_DXT3:        _dec_dxt3($img, $raw, $w, $h); break;
        case VTF_FMT_DXT5:        _dec_dxt5($img, $raw, $w, $h); break;
        default:
            // Fallback: fill checkerboard so broken textures are obvious
            _fill_checkerboard($img, $w, $h);
    }
    return $img;
}

// ─── Uncompressed decoders ───────────────────────────────────────────────────

function _dec_rgba8888($img, string $d, int $w, int $h, string $order): void {
    $i = 0;
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $b0 = ord($d[$i]);   $b1 = ord($d[$i+1]);
        $b2 = ord($d[$i+2]); $b3 = ord($d[$i+3]);
        $i += 4;
        switch ($order) {
            case 'RGBA': [$r,$g,$b,$a] = [$b0,$b1,$b2,$b3]; break;
            case 'ABGR': [$r,$g,$b,$a] = [$b3,$b2,$b1,$b0]; break;
            case 'ARGB': [$r,$g,$b,$a] = [$b1,$b2,$b3,$b0]; break;
            case 'BGRA': [$r,$g,$b,$a] = [$b2,$b1,$b0,$b3]; break;
            case 'BGRX': [$r,$g,$b,$a] = [$b2,$b1,$b0,255]; break;
            default:     [$r,$g,$b,$a] = [$b0,$b1,$b2,$b3];
        }
        imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, 127 - ($a >> 1)));
    }
}

function _dec_rgb888($img, string $d, int $w, int $h, bool $bgr): void {
    $i = 0;
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $b0=ord($d[$i]); $b1=ord($d[$i+1]); $b2=ord($d[$i+2]); $i+=3;
        [$r,$g,$b] = $bgr ? [$b2,$b1,$b0] : [$b0,$b1,$b2];
        imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, 0));
    }
}

function _dec_rgb565($img, string $d, int $w, int $h, bool $bgr): void {
    $i = 0;
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $v = unpack('v', substr($d,$i,2))[1]; $i+=2;
        if ($bgr) {
            $b = ($v & 0x1F) << 3;
            $g = (($v>>5)&0x3F) << 2;
            $r = (($v>>11)&0x1F) << 3;
        } else {
            $r = ($v & 0x1F) << 3;
            $g = (($v>>5)&0x3F) << 2;
            $b = (($v>>11)&0x1F) << 3;
        }
        imagesetpixel($img, $x, $y, imagecolorallocatealpha($img,$r,$g,$b,0));
    }
}

function _dec_bgra4444($img, string $d, int $w, int $h): void {
    $i = 0;
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $v = unpack('v', substr($d,$i,2))[1]; $i+=2;
        $b = ($v & 0xF) * 17;
        $g = (($v>>4)&0xF) * 17;
        $r = (($v>>8)&0xF) * 17;
        $a = (($v>>12)&0xF) * 17;
        imagesetpixel($img,$x,$y,imagecolorallocatealpha($img,$r,$g,$b, 127-($a>>1)));
    }
}

function _dec_bgra5551($img, string $d, int $w, int $h, bool $hasAlpha): void {
    $i = 0;
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $v = unpack('v', substr($d,$i,2))[1]; $i+=2;
        $b = ($v & 0x1F) << 3;
        $g = (($v>>5)&0x1F) << 3;
        $r = (($v>>10)&0x1F) << 3;
        $a = $hasAlpha ? ((($v>>15)&1)*255) : 255;
        imagesetpixel($img,$x,$y,imagecolorallocatealpha($img,$r,$g,$b, 127-($a>>1)));
    }
}

function _dec_i8($img, string $d, int $w, int $h): void {
    for ($y=0;$y<$h;$y++) for ($x=0;$x<$w;$x++) {
        $v = ord($d[$y*$w+$x]);
        imagesetpixel($img,$x,$y,imagecolorallocatealpha($img,$v,$v,$v,0));
    }
}

function _dec_ia88($img, string $d, int $w, int $h): void {
    $i=0;
    for ($y=0;$y<$h;$y++) for ($x=0;$x<$w;$x++) {
        $v=ord($d[$i]); $a=ord($d[$i+1]); $i+=2;
        imagesetpixel($img,$x,$y,imagecolorallocatealpha($img,$v,$v,$v, 127-($a>>1)));
    }
}

function _dec_a8($img, string $d, int $w, int $h): void {
    for ($y=0;$y<$h;$y++) for ($x=0;$x<$w;$x++) {
        $a=ord($d[$y*$w+$x]);
        imagesetpixel($img,$x,$y,imagecolorallocatealpha($img,255,255,255, 127-($a>>1)));
    }
}

// ─── DXT / BCn decoders ───────────────────────────────────────────────────────

/**
 * Expand two RGB565 endpoints into a 4-color palette.
 * Returns [[r,g,b], [r,g,b], [r,g,b], [r,g,b]]
 */
function _dxt_palette(int $c0raw, int $c1raw, bool $dxt1alpha): array {
    $r0 = (($c0raw>>11)&0x1F)*255/31;  $g0=(($c0raw>>5)&0x3F)*255/63; $b0=($c0raw&0x1F)*255/31;
    $r1 = (($c1raw>>11)&0x1F)*255/31;  $g1=(($c1raw>>5)&0x3F)*255/63; $b1=($c1raw&0x1F)*255/31;
    $r0=(int)$r0; $g0=(int)$g0; $b0=(int)$b0;
    $r1=(int)$r1; $g1=(int)$g1; $b1=(int)$b1;

    $pal = [[$r0,$g0,$b0], [$r1,$g1,$b1], [0,0,0], [0,0,0]];

    if (!$dxt1alpha || $c0raw > $c1raw) {
        $pal[2] = [(int)(($r0*2+$r1)/3), (int)(($g0*2+$g1)/3), (int)(($b0*2+$b1)/3)];
        $pal[3] = [(int)(($r0+$r1*2)/3), (int)(($g0+$g1*2)/3), (int)(($b0+$b1*2)/3)];
    } else {
        $pal[2] = [(int)(($r0+$r1)/2), (int)(($g0+$g1)/2), (int)(($b0+$b1)/2)];
        $pal[3] = null; // transparent
    }
    return $pal;
}

function _dec_dxt1($img, string $d, int $w, int $h, bool $alpha): void {
    $bw = max(1,(int)ceil($w/4));
    $bh = max(1,(int)ceil($h/4));
    $i = 0;
    for ($by=0;$by<$bh;$by++) for ($bx=0;$bx<$bw;$bx++) {
        $c0 = unpack('v',substr($d,$i,2))[1]; $i+=2;
        $c1 = unpack('v',substr($d,$i,2))[1]; $i+=2;
        $idx= unpack('V',substr($d,$i,4))[1]; $i+=4;
        $pal = _dxt_palette($c0,$c1,$alpha);
        for ($py=0;$py<4;$py++) for ($px=0;$px<4;$px++) {
            $cx=$bx*4+$px; $cy=$by*4+$py;
            if ($cx>=$w||$cy>=$h) continue;
            $ci = ($idx>>(($py*4+$px)*2))&3;
            if ($pal[$ci]===null) {
                imagesetpixel($img,$cx,$cy,imagecolorallocatealpha($img,0,0,0,127));
            } else {
                [$r,$g,$b]=$pal[$ci];
                imagesetpixel($img,$cx,$cy,imagecolorallocatealpha($img,$r,$g,$b,0));
            }
        }
    }
}

function _dec_dxt3($img, string $d, int $w, int $h): void {
    $bw=max(1,(int)ceil($w/4)); $bh=max(1,(int)ceil($h/4));
    $i=0;
    for ($by=0;$by<$bh;$by++) for ($bx=0;$bx<$bw;$bx++) {
        // 8 bytes explicit alpha (4-bit per texel)
        $alphaBlock = substr($d,$i,8); $i+=8;
        $c0=unpack('v',substr($d,$i,2))[1]; $i+=2;
        $c1=unpack('v',substr($d,$i,2))[1]; $i+=2;
        $idx=unpack('V',substr($d,$i,4))[1]; $i+=4;
        $pal=_dxt_palette($c0,$c1,false);
        for ($py=0;$py<4;$py++) for ($px=0;$px<4;$px++) {
            $cx=$bx*4+$px; $cy=$by*4+$py;
            if ($cx>=$w||$cy>=$h) continue;
            $ci=($idx>>(($py*4+$px)*2))&3;
            [$r,$g,$b]=$pal[$ci];
            // 4-bit alpha from explicit block
            $abyte = ord($alphaBlock[(int)(($py*4+$px)/2)]);
            $a4 = ($py*4+$px)%2===0 ? ($abyte&0xF) : (($abyte>>4)&0xF);
            $a = $a4*17;
            imagesetpixel($img,$cx,$cy,imagecolorallocatealpha($img,$r,$g,$b,127-($a>>1)));
        }
    }
}

function _dec_dxt5($img, string $d, int $w, int $h): void {
    $bw=max(1,(int)ceil($w/4)); $bh=max(1,(int)ceil($h/4));
    $i=0;
    for ($by=0;$by<$bh;$by++) for ($bx=0;$bx<$bw;$bx++) {
        // Alpha block: 2 endpoint bytes + 6 bytes of 3-bit indices (48 bits, 16 texels)
        $a0=ord($d[$i]); $a1=ord($d[$i+1]); $i+=2;
        // Store 6 alpha-index bytes individually for safe 3-bit extraction
        $ab = [ord($d[$i]),ord($d[$i+1]),ord($d[$i+2]),
               ord($d[$i+3]),ord($d[$i+4]),ord($d[$i+5])];
        $i+=6;
        // Build 8-value alpha palette
        $apal=[$a0,$a1,0,0,0,0,0,0];
        if ($a0>$a1) {
            $apal[2]=(int)((6*$a0+1*$a1)/7);
            $apal[3]=(int)((5*$a0+2*$a1)/7);
            $apal[4]=(int)((4*$a0+3*$a1)/7);
            $apal[5]=(int)((3*$a0+4*$a1)/7);
            $apal[6]=(int)((2*$a0+5*$a1)/7);
            $apal[7]=(int)((1*$a0+6*$a1)/7);
        } else {
            $apal[2]=(int)((4*$a0+1*$a1)/5);
            $apal[3]=(int)((3*$a0+2*$a1)/5);
            $apal[4]=(int)((2*$a0+3*$a1)/5);
            $apal[5]=(int)((1*$a0+4*$a1)/5);
            $apal[6]=0; $apal[7]=255;
        }
        $c0=unpack('v',substr($d,$i,2))[1]; $i+=2;
        $c1=unpack('v',substr($d,$i,2))[1]; $i+=2;
        $idx=unpack('V',substr($d,$i,4))[1]; $i+=4;
        $pal=_dxt_palette($c0,$c1,false);

        for ($py=0;$py<4;$py++) for ($px=0;$px<4;$px++) {
            $cx=$bx*4+$px; $cy=$by*4+$py;
            if ($cx>=$w||$cy>=$h) continue;

            $texel = $py*4+$px;
            // Extract 3-bit alpha index safely from 6 individual bytes
            // Each texel uses 3 bits; 16 texels = 48 bits across 6 bytes
            $bit  = $texel * 3;           // first bit position (0-based)
            $byte = (int)($bit / 8);      // which byte
            $boff = $bit % 8;             // bit offset within that byte
            if ($boff <= 5) {
                // 3 bits fit in one byte
                $ai = ($ab[$byte] >> $boff) & 7;
            } else {
                // spans two bytes
                $ai = (($ab[$byte] >> $boff) | ($ab[$byte+1] << (8-$boff))) & 7;
            }

            $ci=($idx>>(($py*4+$px)*2))&3;
            [$r,$g,$b]=$pal[$ci];
            $a=$apal[$ai];
            imagesetpixel($img,$cx,$cy,imagecolorallocatealpha($img,$r,$g,$b,127-($a>>1)));
        }
    }
}

function _fill_checkerboard($img, int $w, int $h): void {
    for ($y=0;$y<$h;$y++) for ($x=0;$x<$w;$x++) {
        $c=(($x/8+(int)($y/8))%2===0) ? 180 : 100;
        imagesetpixel($img,$x,$y,imagecolorallocate($img,$c,0,$c));
    }
}

// ─── Entry point (if called directly) ───────────────────────────────────────
// Uncomment and adjust path to test:
serve_vtf(__DIR__ . '/demo.vtf');
