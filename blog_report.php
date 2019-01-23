<?php
// ============ CONFIG ============

$url_list	= 'new_list.txt'; // this is DEFAULT list of URLs. But you can either:
                                  //   1. provide the filename in $_GET['parameter'] calling "yourhost.com/path/blog_report_php?list=[FILENAME.TXT]", OR
                                  //   2. on call by crontab provide filename as first argument: "php /path/to/script/blog_report.php FILENAME.TXT".

$report_email	= '';
$report_subject	= 'Blog List Report';

$use_file_get_contents = false; // FALSE if we use TCP-sockets if "file_get_contents()" doesn't works.
$use_output_buffering  = false; // FALSE if we don't want output buffering. (Let's display output imediately line by line.)
                                // ...also, if you're using Nginx, "proxy_buffering" should be disabled. (Set "proxy_buffering off" in "nginx.conf".)

// miscellaneous
$http_request_headers = "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/71.0.3578.98 Safari/537.36\r\n".
                        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8\r\n".
                        "Accept-Encoding: gzip, deflate\r\n".
                        "Accept-Language: en-US,en;q=0.9\r\n";


// ============== GO ==============

function print_buffer() {
  $buf = ob_get_contents();
  ob_end_clean();
  print $buf;
  flush();
  return $buf;
}

if (!function_exists('http_chunked_decode')) {
  function http_chunked_decode($str) {
    for ($res=''; !empty($str); $str = trim($str)) {
      $pos = strpos($str, "\r\n");
      $len = hexdec(substr($str, 0, $pos));
      $res.= substr($str, $pos + 2, $len);
      $str = substr($str, $pos + 2 + $len);
    }
    return $res;
  }
}

function get_redirect_url($url) {
  global $http_request_headers;

  $url_parts = @parse_url($url);
  if (!$url_parts) return 'Bad URL.';
  if (!isset($url_parts['host'])) return 'Can\'t serve relative URL.';
  if (!isset($url_parts['path'])) $url_parts['path'] = '/';

  $port = isset($url_parts['port']) ? (int)$url_parts['port'] : 0;
  $is_ssl = (isset($url_parts['scheme']) && $url_parts['scheme'] == 'https') || ($port == 443);
  if (!$port)
    $port = $is_ssl ? 443 : 80;

  if (!$sock = fsockopen(($is_ssl ? 'ssl://' : '').$url_parts['host'], $port, $errno, $errstr, 30))
    return 'Host unreachable.';

  $out = $url_parts['path'].(isset($url_parts['query']) ? '?'.$url_parts['query'] : '');
  $out = "HEAD $out HTTP/1.1\r\n".
         "Host: $url_parts[host]\r\n".
         $http_request_headers.
         "Connection: Close\r\n\r\n";
  fwrite($sock, $out);
  $in = '';
  while (!feof($sock)) $in.= fread($sock, 8192);
  fclose($sock);

  $code = 0;
  if (preg_match('/^HTTP\/[0-9\.]+\s+([0-9]+)/m', $in, $m))
    $code = (int)$m[1];

  $redirect = false;
  if (preg_match('/^Location: (.+?)$/m', $in, $m) && isset($m[1]) && $m[1]) {
    if ($m[1][0] == '/')
      $redirect = $url_parts['scheme'].'://'.$url_parts['host'];
    $redirect.= trim($m[1]);
  }

  return array($code, $redirect);
}

function get_all_redirects($url) { // get all http status codes and redirect urls
  $cnt = 0;
  $http_redirects = array();
  while ($url && ($new_url = get_redirect_url($url)) && is_array($new_url)) {
    if ($cnt > 8) break; // TODO: give Too many redirects error! (Легко тестируется, делай цикличный редирект с http на https и с https на http.)
    ++$cnt;
    if ($new_url[1] && in_array($new_url[1], $http_redirects))
      break;
    $http_redirects[] = $new_url; // code+redirect
    $url = $new_url[1];
  }

  if (!is_array($new_url))
    return $new_url;

  return $http_redirects;
}

// used only if we use "file_get_contents()".
function get_http_error_code() {
  global $http_response_header;
  if (is_array($http_response_header))
    foreach ($http_response_header as $a)
      if ($a) {
        // This is how to generate HTTP status code only...
        $a = explode(':', $a, 2);
        if (!isset($a[1]) && preg_match('/HTTP\/[0-9\.]+\s+([0-9]+)/', $a[0], $a))
          return (int)$a[1]; // pass HTTP code we got to 
      }
  return false;
}

function get_attr_content($str, $attr = false) {
  if (!$attr) $attr = 'content';
  return preg_match("/$attr=\s*('|\")(.*?)\\1/is", $str, $m) && isset($m[2]) ? trim($m[2]) : false;
}

// returns "content" of the meta tag with specified "name" attribute.
// If $attr is false, it returns the content of attribute specified by $attr_name.
function parse_meta($html, $attr, $attr_name = false) {
  $r = '';
  $attr_cont = $attr ? preg_quote($attr, '/') : '(.*?)';
  $attr_name = $attr_name ? preg_quote($attr_name, '/') : 'name';

  if (preg_match("/<meta\s+([^>]*?)$attr_name=\s*?(\"|')?$attr_cont\\2([^>]*?)>/is", $html, $m)) {
    if (!$attr)
      $r = trim($m[3]);

    // result either in $m[1] or $m[3].
    elseif (!$r = get_attr_content($m[1]))
      $r = get_attr_content($m[3]);
  }
  return $r;
}

function get_tag_inner_html($html, $tag) {
  return preg_match("/<$tag(\s.*?)?>(.+?)<\/$tag>/is", $html, $m) || !isset($m[2]) ? trim($m[2]) : false;
}

function fetch_and_parse_page($url) {
  global $use_file_get_contents, $http_response_header, $http_request_headers;


  // retrieving remote HTML in proper way...
  if ($use_file_get_contents) {

    // prepading request
    $opts = array(
      'http'=>array(
        'header'=> $http_request_headers,
      )
    );
    $http_response_header = false;
    if (!$html = @file_get_contents($url, false, stream_context_create($opts))) {
      return ($http_error = get_http_error_code()) ?
        sprintf('HTTP error #%s.', get_http_error_code($http_error)) : 'Host unreachable.';
    }

  }else {
    $url_parts = @parse_url($url);
    if (!$url_parts) return 'Bad URL.';
    if (!isset($url_parts['host'])) return 'Can\'t serve relative URL.';
    if (!isset($url_parts['path'])) $url_parts['path'] = '/';

    $port = isset($url_parts['port']) ? (int)$url_parts['port'] : 0;
    $is_ssl = (isset($url_parts['scheme']) && $url_parts['scheme'] == 'https') || ($port == 443);
    if (!$port)
      $port = $is_ssl ? 443 : 80;

    if (!$sock = fsockopen(($is_ssl ? 'ssl://' : '').$url_parts['host'], $port, $errno, $errstr, 30))
      return 'Host unreachable.';

    $get = $url_parts['path'].(isset($url_parts['query']) ? '?'.$url_parts['query'] : '');
    $get = "GET $get HTTP/1.1\r\n".
           "Host: $url_parts[host]\r\n".
           $http_request_headers.
           "Connection: Close\r\n\r\n";
    fwrite($sock, $get);
    $html = '';
    while (!feof($sock)) $html.= fgets($sock, 8192); // we're getting both HTTP headers and HTML content here.
    fclose($sock);
  }

  // split header and content
  list($header, $html) = explode(strpos($html, "\r\n\r\n") !== false ? "\r\n\r\n" : "\n\n", $html, 2); // malformed header with \n\n?

  if (!$html)
    return 'No content after HTTP header.';

  // if the content is chunked -- decode it.
  if (preg_match('/^transfer-encoding: (.+?)$/im', $header, $m) && isset($m[1]) && $m[1] &&
      (trim($m[1]) == 'chunked'))
    $html = http_chunked_decode($html);

  // if the content is gzipped -- ungzip it.
  if (preg_match('/^content-encoding: (.+?)$/im', $header, $m) && isset($m[1]) && $m[1] &&
      (trim($m[1]) == 'gzip'))
    $html = gzdecode($html);

  // getting the HEAD section.
  if (!preg_match('/<head(\s.*?)?>(.+?)(<\/head>|<body\s)/is', $html, $m) || !isset($m[2]))
    return 'No header section.';

  $head = $m[2];

  // first of all -- determinate the character set of incoming data. We looking both at <head> section of HTML and to HTTP response. <head> have higher priority.
  if ((!$charset = parse_meta($head, 'content-type', 'http-equiv')) &&
      (!$charset = parse_meta($head, false, 'charset'))) {
    // try to find in HTTP response...
    if (preg_match('/^content-type: (.+?)$/im', $header, $m) && isset($m[1]) && $m[1])
      $charset = trim($m[1]);
  }
  if ($charset) {
    if (strpos($charset, $i = 'charset=') !== false)
      list($i, $charset) = explode($i, $charset);
    if (strtolower($charset) == 'utf8') // fixing bad charcode
      $charset = 'utf-8';

    $is_utf8 = strtolower($charset) == 'utf-8';
  }else
    $is_utf8 = true; // actually we don't know the charcode, but don't need convert it.
  

  // title
  if (!$title = (preg_match('/<title>(.+?)<\/title>/si', $head, $m) && isset($m[1])) ? $m[1] : false)
    $title = parse_meta($head, 'og:title', 'property'); // check OpenGraph's title too.
  if ($title && !$is_utf8)
    $title = mb_convert_encoding($title, 'utf-8', $charset);

  // description. First looking for standard description, then into OpenGraph's og:description.
  if (!$description = parse_meta($head, 'description'))
    $description = parse_meta($head, 'og:description', 'property');
  if ($description && !$is_utf8)
    $description = mb_convert_encoding($description, 'utf-8', $charset);

  // Wordpress version
  if ($wordpress = parse_meta($head, 'generator'))
    if (($i = stripos($wordpress, ($j = 'wordpress'))) === 0)
      $wordpress = substr($wordpress, strlen($j)+1);
    else
      $wordpress = ''; // not Wordpress.

  // collecting the information to return
  return array(
      'url'         => $url,
      'title'       => $title,
      'keywords'    => parse_meta($head, 'keywords'),
      'description' => $description,
      'charset'     => $charset,
      'wordpress'   => $wordpress,
    );
}

// ============ end of functions ==============


// Checking the arguments...
// If command line arguments is set -- processing CVS file.
if (isset($argv[1]) && file_exists($argv[1]))
  $url_list = $argv[1];
elseif (isset($_GET['list']) && ($i = $_GET['list']) && file_exists($i))
  $url_list = $i;



// PREPARING...

$mtime = microtime(1);
// Loading the input URLS from the XML file by name input_url.xml
if (!$urls = file_get_contents($url_list)) {
  print 'Can\'t read file with URLs.';
  exit;
}

if ($urls[0] == '<') { // It's XML?
  if ((!$urls = simplexml_load_string($urls)) ||
      (!$urls = $urls->children())) {
    print 'Unable to read or parse XML file.';
    exit;
  }
}else
  $urls = explode("\n", $urls);


// Let's disable output buffers.
if (!$use_output_buffering) {
  ini_set('output_buffering', 0);
  ini_set('zlib.output_compression', 0);
  ini_set('session.use_trans_sid', 0);
  ob_implicit_flush(1);
  @ob_end_flush(); // it doesn't works (returns notice) on my local Windows PC, but required to start output without buffering
}


$out = '';
ob_start();
?>
<html>
<head>
<meta http-equiv="content-type" content="text/html; charset=UTF-8" />
<title>Wordpress Blog Report</title>
<style>
th {
	background-color: #EEE;
}
.cell_num {
	text-align: right;
}
.cell_charset,
.cell_wp_version {
	text-align: center;
}
.descr {
	font-size: .8em;
	margin: .4em 0 0 20px;
}
.redir {
	font-size: .8em;
	padding: 2px 4px;
}
.error {
	background-color: #FFCECE; /* light red */
	font-weight: bold;
}
.warn {
	background-color: #FFFF9A; /* light yellow */
}

.report_table {
	margin: 0 auto;
	width: 100%;
}
.report_table td {
	max-width: 500px;
	overflow: hidden;
}
</style>
<style class="strip_from_email">
div#in_progress::after {
	margin-top: 1.5em;
	display: block;
	content: url(./ani-fountain.svg);
}
</style>
</head>
<body>

<div style="width: 85%; margin: 0 auto; text-align: center;">
  <p><strong><?=$report_subject?></strong> for &ldquo;<strong><?=$url_list?></strong>&rdquo; (<a href="http://wordpress.org/download/" target="_blank">Fresh Wordpress Download</a>)</p>
  <div id="in_progress">
  <table cellspacing="0" cellpadding="3" border="1" class="report_table">
    <tr>
      <th class="ralign">#</th>
      <th>Website Name</th>
      <th>Title &amp; Description</th>
      <th>Chr</th>
      <th>WP ver</th>
    </tr>
<?
$out.= print_buffer();

$cnt = 0;
foreach ($urls as $url)
  if ($url = trim($url)) { //each URL from child tag of the input XML file
    ++$cnt;
    set_time_limit(120); // +2 minutes before timeout

    // looking for all redirects / retrieving the final destination...
    $redirects = '';
    if ((!$http_redirects = get_all_redirects($url)) || !is_array($http_redirects)) {
      $data = $http_redirects; // error message
    }else {
      $final_destination = $url;
      foreach ($http_redirects as $key => $val)
        if ($val[1]) {
          $redirects.= "<div><a href=\"$val[1]\">$val[1]</a> ($val[0])</div>";
          $final_destination = $val[1];
        }

      // get and parse the website URL
      $data = fetch_and_parse_page($final_destination);
      // we need redirect URL too.
      if ($redirects)
        $redirects = "<div class=\"warn redir\">$redirects</div>";
    }

    $line = <<<END
<tr>
  <td class="cell_num">$cnt</td>
  <td><a href="$url">$url</a>$redirects</td>

END;
    if (is_array($data)) {
      $line.= <<<END
  <td>$data[title]<div class="descr">$data[description]</div></td>
  <td class="cell_charset">$data[charset]</td>
  <td class="cell_wp_version">$data[wordpress]</td>

END;
    }else {
      $line.= <<<END
  <td colspan="3" class="error">$data</td>

END;
    }
    $line.= <<<END
</tr>

END;
    print $line;
    flush();
    $out.= $line;
  }

ob_start();
?>
  </table>
  </div>
  <script class="strip_from_email">
    // <![CDATA[
    document.getElementById("in_progress").id = ""
    // ]]>
  </script>
  <p><i>Generated in <?=number_format((microtime(1) - $mtime), 2)?> seconds by &ldquo;<?=basename($_SERVER['SCRIPT_NAME'])?>&rdquo; on Sam-san.</i></p>
</div>

</body>
</html>
<?
$out.= print_buffer();

// strip some stuff which shouldn't ever be in email.
$out = preg_replace('/<(\w+)\s+?[^>]*?class=\s*?("|\')strip_from_email\\2[^>]*?>(.*?)<\/\\1>/is', '', $out);

// Sending report via email...
if ($report_email) {
  $email_headers = "From: <$report_email>\r\n".
                   "MIME-Version: 1.0\r\n".
                   "Content-type: text/html; charset=UTF-8\r\n";

  if (mail($report_email, $report_subject, $out, $email_headers))
    print "Report mail has been successfully sent to $report_email.";
  else
    print "Report mail failed.";
}
