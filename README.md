<h1>VTF to Image API</h1>

<p>
A pure PHP API for rendering Valve Texture Format (VTF) files to PNG images.
No external tools or PHP extensions beyond GD are required.
</p>

<h2>Features</h2>

<ul>
<li>Supports 20+ VTF image formats.</li>
<li>Supports RGBA8888, ABGR8888, RGB888, BGR888, BGRA8888, ARGB8888, BGRX8888.</li>
<li>Supports RGB565, BGR565, BGRA4444, BGRA5551, BGRX5551.</li>
<li>Supports A8, I8 and IA88 formats.</li>
<li>Supports DXT1 (BC1), DXT3 (BC2) and DXT5 (BC3).</li>
<li>Extracts the highest-resolution mipmap.</li>
<li>Converts textures to PNG with transparency support.</li>
<li>REST API endpoint included.</li>
<li>No external dependencies.</li>
</ul>

<h2>Requirements</h2>

<ul>
<li>PHP 7.4 or newer.</li>
<li>GD extension enabled.</li>
</ul>

<h2>Installation</h2>

<pre>
git clone https://github.com/lexicon06/vtf-api.git
cd vtf-api
</pre>

<h2>API Usage</h2>

<h3>Endpoints</h3>

<pre>
GET /?file=/path/to/texture.vtf
GET /index.php?file=/path/to/texture.vtf
</pre>

<h3>Example</h3>

<pre>
http://localhost/vtf-api/?file=textures/map1.vtf
</pre>

<p>Save the result to a file:</p>

<pre>
curl "http://localhost/vtf-api/?file=textures/map1.vtf" --output texture.png
</pre>

<h2>PHP Usage</h2>

<pre>
require_once 'vtf.php';

// Serve directly to browser
serve_vtf('/path/to/texture.vtf');

// Get GD image resource
$img = vtf_to_gd('/path/to/texture.vtf');
imagepng($img, 'output.png');
imagedestroy($img);
</pre>

<h2>Docker</h2>

<pre>
docker build -t vtf-api .
docker run -p 8080:80 -v $(pwd)/textures:/var/www/textures vtf-api
</pre>

<h2>Response</h2>

<ul>
<li><b>Success:</b> Returns a PNG image with Content-Type: image/png.</li>
<li><b>Error:</b> Returns a JSON error message.</li>
</ul>

<h2>Supported VTF Versions</h2>

<ul>
<li>VTF 7.0 to 7.5.</li>
<li>Single-frame textures (first frame only).</li>
<li>2D textures only.</li>
<li>No cubemap support.</li>
</ul>

<h2>Project Structure</h2>

<pre>
vtf-api/
│
├── index.php
├── vtf.php
├── composer.json
├── Dockerfile
├── docker-compose.yml
├── .htaccess
├── .gitignore
│
├── textures/
│
├── uploads/
│
└── examples/
    ├── basic.html
    └── example.php
</pre>

<h2>Quick Start</h2>

<ol>
<li>Clone the repository.</li>
<li>Place your .vtf files inside the textures directory.</li>
<li>Start a PHP server.</li>
<li>Open the API URL in your browser.</li>
</ol>

<pre>
php -S localhost:8000
</pre>

<p>Example:</p>

<pre>
http://localhost:8000/index.php?file=textures/map1.vtf
</pre>

<h2>License</h2>

<p>MIT License</p>

<hr>

<p>
VTF to Image API is a lightweight, dependency-free solution for converting
Valve Texture Format files into PNG images entirely in PHP.
</p>
