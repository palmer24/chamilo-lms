<?php
/* For licensing terms, see /license.txt */

/**
 * Class ImsLtiServiceRequest.
 */
abstract class ImsLtiServiceRequest
{
    /**
     * @var string
     */
    protected $responseType;

    /**
     * @var SimpleXMLElement
     */
    protected $xmlHeaderInfo;

    /**
     * @var SimpleXMLElement
     */
    protected $xmlRequest;

    /**
     * @var ImsLtiServiceResponseStatus
     */
    protected $statusInfo;

    /**
     * @var mixed
     */
    protected $responseBodyParam;

    /**
     * ImsLtiServiceRequest constructor.
     *
     * @param SimpleXMLElement $xml
     */
    public function __construct(SimpleXMLElement $xml)
    {
        $children = $xml->imsx_POXBody->children();

        $this->xmlHeaderInfo = $xml->imsx_POXHeader->imsx_POXRequestHeaderInfo;
        $this->xmlRequest = $children[0];
    }

    protected function processHeader()
    {
        $info = $this->xmlHeaderInfo;

        error_log("Service Request: tool version {$info->imsx_version} message ID {$info->imsx_messageIdentifier}");
    }

    abstract protected function processBody();

    /**
     * @return ImsLtiServiceResponse|null
     */
    private function generateResponse()
    {
        $response = ImsLtiServiceResponseFactory::create(
            $this->responseType,
            $this->statusInfo,
            $this->responseBodyParam
        );

        return $response;
    }

    /**
     * @return ImsLtiServiceResponse|null
     */
    public function process()
    {
        $this->processHeader();
        $this->processBody();

        return $this->generateResponse();
    }
}