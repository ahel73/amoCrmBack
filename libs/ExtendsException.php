<?php
class WebHookException extends Exception{}; // когда не получен токен
class LoopException extends WebHookException{}; // в цикле