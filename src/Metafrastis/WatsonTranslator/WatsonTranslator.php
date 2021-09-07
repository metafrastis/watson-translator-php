<?php

namespace Metafrastis\WatsonTranslator;

class WatsonTranslator {

	public $queue = [];
	public $response;
	public $responses = [];

	public function translate($args = [], $opts = []) {
		if (is_object($args)) {
			$args = json_decode(json_encode($args), true);
		}
		if (is_string($args)) {
			if (($arr = json_decode($args, true))) {
				$args = $arr;
			} else {
				parse_str($args, $arr);
				if ($arr) {
					$args = $arr;
				}
			}
		}
		$args = is_array($args) ? $args : [];
		$args['from'] = isset($args['from']) ? $args['from'] : null;
		$args['to'] = isset($args['to']) ? $args['to'] : null;
		$args['text'] = isset($args['text']) ? $args['text'] : null;
		if (!$args['from']) {
			return false;
		}
		if (!$args['to']) {
			return false;
		}
		if (!$args['text']) {
			return false;
		}
		$url = 'https://www.ibm.com/demos/live/watson-language-translator/api/translate/text';
		$headers = [
			'Accept: application/json, text/plain, '.'*'.'/'.'*',
			'Accept-Language: en-US,en;q=0.5',
			'Connection: keep-alive',
			'Content-Type: application/json;charset=utf-8',
			'Origin: https://www.ibm.com',
			'Referer: https://www.ibm.com/demos/live/watson-language-translator/self-service/home',
			'Sec-Fetch-Dest: empty',
			'Sec-Fetch-Mode: cors',
			'Sec-Fetch-Site: same-origin',
			'TE: trailers',
			'User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:91.0) Gecko/20100101 Firefox/91.0',
		];
		$params = ['text' => $args['text'], 'source' => $args['from'], 'target' => $args['to']];
		$params = json_encode($params);
		$options = [
			CURLOPT_CERTINFO => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_SSL_VERIFYPEER => 2,
		];
		$options = array_replace($options, $opts);
		$queue = isset($args['queue']) ? 'translate' : false;
		$response = $this->post($url, $headers, $params, $options, $queue);
		if (!$queue) {
			$this->response = $response;
		}
		if ($queue) {
			return;
		}
		$json = json_decode($response['body'], true);
		if (empty($json['payload']['translations'][0]['translation'])) {
			return false;
		}
		return $json['payload']['translations'][0]['translation'];
	}

	public function request($method, $url, $headers = [], $params = null, $options = [], $queue = false) {
		if (is_string($headers)) {
			$headers = array_values(array_filter(array_map('trim', explode("\x0a", $headers))));
		}
		if (is_array($headers) && isset($headers['headers']) && is_array($headers['headers'])) {
			$headers = $headers['headers'];
		}
		if (is_array($headers)) {
			foreach ($headers as $key => $value) {
				if (is_string($key) && !is_numeric($key)) {
					$headers[$key] = sprintf('%s: %s', $key, $value);
				}
			}
		}
		$opts = [];
		$opts[CURLINFO_HEADER_OUT] = true;
		$opts[CURLOPT_CONNECTTIMEOUT] = 5;
		$opts[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
		$opts[CURLOPT_ENCODING] = '';
		$opts[CURLOPT_FOLLOWLOCATION] = false;
		$opts[CURLOPT_HEADER] = true;
		$opts[CURLOPT_HTTPHEADER] = $headers;
		if ($params !== null) {
			$opts[CURLOPT_POSTFIELDS] = is_array($params) || is_object($params) ? http_build_query($params) : $params;
		}
		$opts[CURLOPT_RETURNTRANSFER] = true;
		$opts[CURLOPT_SSL_VERIFYHOST] = false;
		$opts[CURLOPT_SSL_VERIFYPEER] = false;
		$opts[CURLOPT_TIMEOUT] = 10;
		$opts[CURLOPT_URL] = $url;
		foreach ($opts as $key => $value) {
			if (!array_key_exists($key, $options)) {
				$options[$key] = $value;
			}
		}
		if ($queue) {
			$this->queue[] = ['options' => $options, 'queue' => $queue];
			return;
		}
		$follow = false;
		if ($options[CURLOPT_FOLLOWLOCATION]) {
			$follow = true;
			$options[CURLOPT_FOLLOWLOCATION] = false;
		}
		$errors = 2;
		$redirects = isset($options[CURLOPT_MAXREDIRS]) ? $options[CURLOPT_MAXREDIRS] : 5;
		while (true) {
			$ch = curl_init();
			curl_setopt_array($ch, $options);
			$body = curl_exec($ch);
			$info = curl_getinfo($ch);
			$head = substr($body, 0, $info['header_size']);
			$body = substr($body, $info['header_size']);
			$error = curl_error($ch);
			$errno = curl_errno($ch);
			curl_close($ch);
			$response = [
				'info' => $info,
				'head' => $head,
				'body' => $body,
				'error' => $error,
				'errno' => $errno,
			];
			if ($error || $errno) {
				if ($errors > 0) {
					$errors--;
					continue;
				}
			} elseif ($info['redirect_url'] && $follow) {
				if ($redirects > 0) {
					$redirects--;
					$options[CURLOPT_URL] = $info['redirect_url'];
					continue;
				}
			}
			break;
		}
		return $response;
	}

	public function post($url, $headers = [], $params = [], $options = [], $queue = false) {
		return $this->request('POST', $url, $headers, $params, $options, $queue);
	}

	public function multi($args = []) {
		if (!$this->queue) {
			return [];
		}
		$mh = curl_multi_init();
		$chs = [];
		foreach ($this->queue as $key => $request) {
			$ch = curl_init();
			$chs[$key] = $ch;
			curl_setopt_array($ch, $request['options']);
			curl_multi_add_handle($mh, $ch);
		}
		$running = 1;
		do {
			curl_multi_exec($mh, $running);
		} while ($running);
		$responses = [];
		foreach ($chs as $key => $ch) {
			curl_multi_remove_handle($mh, $ch);
			$body = curl_multi_getcontent($ch);
			$info = curl_getinfo($ch);
			$head = substr($body, 0, $info['header_size']);
			$body = substr($body, $info['header_size']);
			$error = curl_error($ch);
			$errno = curl_errno($ch);
			curl_close($ch);
			$response = [
				'info' => $info,
				'head' => $head,
				'body' => $body,
				'error' => $error,
				'errno' => $errno,
			];
			$this->responses[$key] = $response;
			$options = $this->queue[$key]['options'];
			if ($this->queue[$key]['queue'] === 'translate' || strpos($options[CURLOPT_URL], '/demos/live/watson-language-translator/api/translate/text') !== false) {
				$json = json_decode($body, true);
				if (empty($json['payload']['translations'][0]['translation'])) {
					$responses[$key] = false;
					continue;
				}
				$responses[$key] = $json['payload']['translations'][0]['translation'];
			} else {
				$responses[$key] = $body;
			}
		}
		curl_multi_close($mh);
		$this->queue = [];
		return $responses;
	}

}
