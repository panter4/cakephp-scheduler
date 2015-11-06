CakePHP Scheduler Plugin Extended
========================

Makes scheduling tasks in CakePHP much simpler.



Original Author
------
Trent Richardson [http://trentrichardson.com]
https://github.com/trentrichardson/cakephp-scheduler


Major modifications:
------
1. All scheduled tasks are now forked processes
2. interval can be cron syntax



new requirements
------
- PHP 5.4+
- mtdowling/cron-expression
- duncan3dc/fork-helper