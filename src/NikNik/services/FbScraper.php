<?php

namespace NikNik\services;


use Goutte\Client;
use Symfony\Component\BrowserKit\CookieJar;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;


/**
 * Class FbScraper
 * @package NikNik\services
 */
class FbScraper
{
    /**
     * Home page URI
     *
     * @var string
     */
    const FB_HOME = 'https://www.facebook.com/';

    /**
     * Login page URI
     *
     * @var string
     */
    const LOGIN_URI = 'https://www.facebook.com/login/';
    
    /**
     * Goutte client
     *
     * @var Client
     */
    protected $client;

    /**
     * FbScraper constructor.
     *
     * @param CookieJar $cookieJar pass jar if you want to restore previous session
     */
    public function __construct(CookieJar $cookieJar = null)
    {
        $this->client = new Client(array(), null, $cookieJar);
    }

    /**
     * Login to facebook.
     * Returns true if successfully authenticated
     * If you've set cookieJar before - it'll check the current auth and require new one only if it is expired
     *
     * @param string $email
     * @param string $pass
     * @return bool true if successfully authenticated
     */
    public function authenticate($email, $pass)
    {
        $loginPageResult = $this->request('GET', self::LOGIN_URI);

        if (!$this->isLoginPage($loginPageResult)) {
            return true;
        }

        $loginForm = $loginPageResult->crawler->filter('#loginbutton')->first()->form();
        $authData = array('email' => $email, 'pass' => $pass);

        $submitResult = $this->submit($loginForm, $authData);

        return !$this->isLoginPage($submitResult);
    }

    /**
     * Get facebook username by given id
     *
     * @param string $id
     * @return string
     *
     * @throws FbNotAuthorizedException if authorization is expired
     * @throws FbUsernameNotFoundException if there is no username (only id)
     * @throws \Exception if something goes wrong
     */
    public function getUsername($id)
    {
        $pageResult =  $this->request('GET', self::FB_HOME . $id);
        $username = $this->extractUserName($pageResult->crawler);

        if (!$this->checkResponseCode($pageResult->response)) {
            throw new \Exception("Error happens. Response code {$pageResult->response->getStatus()}");
        }

        if ($username === (string) $id) {
            if (!$this->checkAuth()) {
                throw new FbNotAuthorizedException();
            }
            throw new FbUsernameNotFoundException($username);
        }

        return $username;
    }

    /**
     * Returns cookies that can be serialized
     *
     * @return CookieJar
     */
    public function getCookieJar() {
        return $this->client->getCookieJar();
    }

    /**
     * Checks if auth is still ok
     *
     * @return bool
     */
    public function checkAuth() {
        return $this->client->getCookieJar()->get('xs') !== null;
    }

    /**
     * @param $method
     * @param $uri
     * @param array $parameters
     * @param array $files
     * @param array $server
     * @param null $content
     * @param bool $changeHistory
     * @return FbRequestResult
     */
    protected function request(
        $method, $uri, $parameters = array(),
        $files = array(), $server = array(), $content = null, $changeHistory = true)
    {
        $crawler = $this->client->request($method, $uri, $parameters, $files, $server, $content, $changeHistory);
        $response = $this->client->getResponse();

        return new FbRequestResult($crawler, $response);
    }


    /**
     * @param Form $form
     * @param array $values
     * @return FbRequestResult
     */
    protected function submit($form, $values = array())
    {
        $crawler =  $this->client->submit($form, $values);
        $response = $this->client->getResponse();

        return new FbRequestResult($crawler, $response);
    }

    /**
     * Checks response code
     *
     * @param $lastResponse
     * @param int $target
     * @return bool
     */
    protected function checkResponseCode($lastResponse, $target = 200) {
//        $lastResponse = $this->client->getResponse();

        return (int) $lastResponse->getStatus() === $target;
    }

    /**
     * Checks if given string is username:
     * decline strings that contains '/' or '?' characters
     *
     * @param string $username
     * @return bool
     */
    protected function isUsername($username)
    {
        return preg_match('/^[^\/\?]*$/', $username) === 1;
    }


    /**
     * @param $url
     * @return int
     */
    protected function isProfilePage($url)
    {
        $res = preg_match('/profile\.php\?id=([0-9]+)$/', $url, $matches);
        return $res === 1 ? $matches[1] : false;
    }

    /**
     * @param $submitResult
     * @return bool
     */
    protected function isLoginUri($submitResult)
    {
        return strpos($submitResult->crawler->getUri(), '/login') !== false;
    }

    /**
     * @param FbRequestResult $loginPageResult
     * @return bool
     */
    protected function isLoginPage(FbRequestResult $loginPageResult)
    {
        return !$this->checkResponseCode($loginPageResult->response) || $this->isLoginUri($loginPageResult);
    }

    /**
     * @param Crawler $crawler
     * @return mixed
     * @throws \Exception
     */
    protected function extractUserName(Crawler $crawler)
    {
        $str = str_replace(self::FB_HOME, '', $crawler->getUri());

        if ($this->isUsername($str)) {
            return $str;
        }

        if ($id = $this->isProfilePage($str)) {
            return $id;
        }

        if (!$this->checkAuth()) {
            throw new FbNotAuthorizedException();
        }

        throw new \Exception("Scraper redirected to wrong page");
    }
}
