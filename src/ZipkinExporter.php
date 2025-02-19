<?php
/**
 * Copyright 2018 OpenCensus Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace OpenCensus\Trace\Exporter;

use OpenCensus\Trace\MessageEvent;
use OpenCensus\Trace\Span;
use OpenCensus\Trace\SpanData;

const LIGHTSTEP_HOSTNAME = 'lightstep.hostname';
const LIGHTSTEP_PLATFORM_VERSION = 'lightstep.tracer_platform_version';
const LIGHTSTEP_ACCESS_TOKEN = 'lightstep.access_token';
/**
 * This implementation of the ExporterInterface appends a json
 * representation of the trace to a file.
 *
 * Example:
 * ```
 * use OpenCensus\Trace\Exporter\ZipkinExporter;
 * use OpenCensus\Trace\Tracer;
 *
 * $exporter = new ZipkinExporter('my_app');
 * Tracer::begin($exporter);
 * ```
 */
class ZipkinExporter implements ExporterInterface
{
    const KIND_SERVER = 'SERVER';
    const KIND_CLIENT = 'CLIENT';
    const DEFAULT_ENDPOINT = 'https://ingest.lightstep.com:443/api/v2/spans';
    const KIND_MAP = [
        Span::KIND_UNSPECIFIED => null,
        Span::KIND_SERVER => self::KIND_SERVER,
        Span::KIND_CLIENT => self::KIND_CLIENT
    ];

    /**
     * @var string
     */
    private $endpointUrl;

    /**
     * @var array
     */
    private $localEndpoint;

    /**
    * @var array
    */
    private $defaultAttributes;

    /**
     * Create a new ZipkinExporter
     *
     * @param string $name The name of this application
     * @param string $accessToken The access token for LightStep
     * @param string $endpointUrl (optional) The url for the span reporting
     *        endpoint. **Defaults to** `http://localhost:9411/api/v2/spans`
     * @param array $defaultAttributes(optional) A set of of default tags to be
     *        applied to all spans.
     * @param array $server (optional) The server array to search for the
     *        SERVER_PORT. **Defaults to** $_SERVER
     */
    public function __construct(
        $name,
        $accessToken,
        $endpointUrl = null,
        array $defaultAttributes = null,
        array $server = null
    ) {
        $server = $server ?: $_SERVER;
        $this->endpointUrl = ($endpointUrl === null) ? self::DEFAULT_ENDPOINT : $endpointUrl;
        $this->localEndpoint = [
            'serviceName' => $name
        ];

        if (array_key_exists('SERVER_PORT', $server)) {
            $this->localEndpoint['port'] = intval($server['SERVER_PORT']);
        }

        $this->defaultAttributes= [
          LIGHTSTEP_HOSTNAME => gethostname(),
          LIGHTSTEP_PLATFORM_VERSION => phpversion(),
          LIGHTSTEP_ACCESS_TOKEN => $accessToken
        ];

        if (!empty($defaultAttributes)) {
            $this->defaultAttributes = array_merge($this->defaultAttributes, $defaultAttributes);
        }
    }

    /**
     * Set the localEndpoint ipv4 value for all reported spans. Note that this
     * is optional because the reverse DNS lookup can be slow.
     *
     * @param string $ipv4 IPv4 address
     */
    public function setLocalIpv4($ipv4)
    {
        $this->localEndpoint['ipv4'] = $ipv4;
    }

    /**
     * Set the localEndpoint ipv6 value for all reported spans. Note that this
     * is optional because the reverse DNS lookup can be slow.
     *
     * @param string $ipv6 IPv6 address
     */
    public function setLocalIpv6($ipv6)
    {
        $this->localEndpoint['ipv6'] = $ipv6;
    }

    /**
     * Report the provided Trace to a backend.
     *
     * @param SpanData[] $spans
     * @return bool
     */
    public function export(array $spans)
    {
        if (empty($spans)) {
            return false;
        }
        $endpointUrl = $this->endpointUrl;
        $scheme = parse_url($endpointUrl, PHP_URL_SCHEME);
        $spans = $this->convertSpans($spans);

        try {
            $json = json_encode($spans);
            // if php curl is available, let's use it
            if (function_exists('curl_version')) {
                $ch = curl_init($endpointUrl);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLINFO_HEADER_OUT, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($json)
                ));
                $result = curl_exec($ch);
                curl_close($ch);
            } else {
                $contextOptions = [
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/json',
                        'content' => $json
                    ]
                    ];
                    $context = stream_context_create($contextOptions);
                    file_get_contents($endpointUrl, false, $context);
            }
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Convert spans into Zipkin's expected JSON output format. See
     * <a href="http://zipkin.io/zipkin-api/#/default/post_spans">output format definition</a>.
     *
     * @access private
     *
     * @param SpanData[] $spans
     * @param array $headers [optional] HTTP headers to parse. **Defaults to** $_SERVER
     * @return array Representation of the collected trace spans ready for serialization
     */
    public function convertSpans(array $spans, $headers = null)
    {
        $headers = $headers ?: $_SERVER;

        // True is a request to store this span even if it overrides sampling policy.
        // This is true when the X-B3-Flags header has a value of 1.
        $isDebug = array_key_exists('HTTP_X_B3_FLAGS', $headers) && $headers['HTTP_X_B3_FLAGS'] == '1';

        // True if we are contributing to a span started by another tracer (ex on a different host).
        $isShared = !empty($spans) && $spans[0]->parentSpanId() !== null;

        $zipkinSpans = [];
        foreach ($spans as $span) {
            $startTime = (int)((float) $span->startTime()->format('U.u') * 1000 * 1000);
            $endTime = (int)((float) $span->endTime()->format('U.u') * 1000 * 1000);
            $spanId = str_pad($span->spanId(), 16, '0', STR_PAD_LEFT);
            $parentSpanId = $span->parentSpanId()
                ? str_pad($span->parentSpanId(), 16, '0', STR_PAD_LEFT)
                : null;
            $traceId = str_pad($span->traceId(), 32, '0', STR_PAD_LEFT);

            $attributes = array_merge($this->defaultAttributes, $span->attributes());

            if (empty($attributes)) {
                // force json_encode to render an empty object ("{}") instead of an empty array ("[]")
                $attributes = new \stdClass();
            }

            $zipkinSpan = [
                'traceId' => $traceId,
                'name' => $span->name(),
                'parentId' => $parentSpanId,
                'id' => $spanId,
                'timestamp' => $startTime,
                'duration' => $endTime - $startTime,
                'debug' => $isDebug,
                'shared' => $isShared,
                'localEndpoint' => $this->localEndpoint,
                'tags' => $attributes,
            ];

            if (null !== ($kind = $this->spanKind($span))) {
                $zipkinSpan['kind'] = $kind;
            }

            $zipkinSpans[] = $zipkinSpan;
        }

        return $zipkinSpans;
    }

    private function spanKind(SpanData $span)
    {
        $kind = self::KIND_MAP[$span->kind()];
        if ($kind !== null) {
            return $kind;
        }

        if (strpos($span->name(), 'Sent.') === 0) {
            return self::KIND_CLIENT;
        }

        if (strpos($span->name(), 'Recv.') === 0) {
            return self::KIND_SERVER;
        }

        if ($span->timeEvents()) {
            foreach ($span->timeEvents() as $event) {
                if (!($event instanceof MessageEvent)) {
                    continue;
                }

                switch ($event->type()) {
                    case MessageEvent::TYPE_SENT:
                        return self::KIND_CLIENT;
                        break;
                    case MessageEvent::TYPE_RECEIVED:
                        return self::KIND_SERVER;
                        break;
                }
            }
        }

        return null;
    }
}
