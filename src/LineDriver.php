<?php

namespace BotMan\Drivers\Line;

use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Users\User;
use BotMan\Drivers\Line\Exceptions\WebHookSignatureException;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use BotMan\BotMan\Messages\Outgoing\Question;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use Symfony\Component\HttpFoundation\JsonResponse;

class LineDriver extends HttpDriver
{
    /** @var string */
    protected $signature;

    /** @var array */
    protected $messages = [];

    /**
     * @param Request $request
     */
    public function buildPayload(Request $request)
    {
        $this->payload = new ParameterBag((array)json_decode($request->getContent(), true));
        $this->signature = $request->headers->get('X-Line-Signature', '');
        $this->content = $request->getContent();
        $this->event = Collection::make((array)$this->payload->get('events'));
    }

    /**
     * @return bool
     */
    protected function validateSignature()
    {
        return hash_equals(
            $this->signature,
            base64_encode(hash_hmac('sha256', $this->content, $this->config->get('channel_secret'), true))
        );
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     * @throws WebHookSignatureException
     */
    public function matchesRequest()
    {
        if (!$this->signature) {
            return false;
        }
        if ($this->signature && !$this->validateSignature()) {
            throw new WebHookSignatureException('Line WebHook validate exception, Please check your channel_secret');
        }
        return true;
    }

    public function hasMatchingEvent()
    {
        return false;
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {
        if (empty($this->messages)) {
            $this->loadMessages();
        }

        return $this->messages;
    }

    /**
     * Load Slack messages.
     */
    protected function loadMessages()
    {
        $messageText = $this->event->get('message')['text'] ?? '';

        $user_id = $this->event->get('source')['userId'] ?? '';

        $message = new IncomingMessage($messageText, $user_id, '', $this->event);

        $this->messages = [$message];
    }

    /**
     * @param IncomingMessage $matchingMessage
     * @return User
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        // @todo get more user info
        return new User($matchingMessage->getSender());
    }

    /**
     * @param IncomingMessage $message
     * @return Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        return Answer::create($message->getText())
            ->setValue($message->getText())
            ->setInteractiveReply(true)
            ->setMessage($message);
    }

    /**
     * @param string|Question|IncomingMessage $message
     * @param IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return array
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        if ($message instanceof Question) {
            $text = $message->getText();
            $parameters['buttons'] = $message->getButtons() ?? [];
        } elseif ($message instanceof OutgoingMessage) {
            $parameters['type'] = 'text';
            $parameters['text'] = $message->getText();
            $attachment = $message->getAttachment();
            if (!is_null($attachment)) {
                //@todo image or other type
            }
        } else {
            $parameters['type'] = 'text';
            $parameters['text'] = $message;
        }

        return $parameters;
    }

    /**
     * @param mixed $payload
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function sendPayload($payload)
    {
        return $this->http->post('https://api.line.me/v2/bot/message/reply ', [], $payload);
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->config->get('token'));
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string $endpoint
     * @param array $parameters
     * @param IncomingMessage $matchingMessage
     * @return Response
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
    }
}
