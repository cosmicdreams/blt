<?php

namespace Acquia\Blt\Robo\Hooks;

use Acquia\Blt\Robo\BltTasks;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;

/**
 * This class defines hooks that provide user interaction.
 *
 * These hooks typically use a Wizard to evaluate the validity of config or
 * state and guide the user toward resolving issues.
 */
class CommandEventHook extends BltTasks {

  /**
   * Disable any command listed in the `disable-target` config key.
   *
   * @hook command-event *
   */
  public function skipDisabledCommands(ConsoleCommandEvent $event) {
    $command = $event->getCommand();
    if ($this->isCommandDisabled($command->getName())) {
      $event->disableCommand();
    }
  }

  /**
   * Issues warnings to user if their local environment is mis-configured.
   *
   * @hook command-event *
   */
  public function issueWarnings(ConsoleCommandEvent $event) {
    // The inspector tracks whether warnings have been issued because it is
    // shared in the container.
    $this->getInspector()->issueEnvironmentWarnings();
  }

  /**
   * Execute a command inside of Drupal VM.
   *
   * @hook command-event *
   */
  public function executeInDrupalVm(ConsoleCommandEvent $event) {
    // @todo Create global option to opt-out of this. E.g., --execute-on-host.
    $command = $event->getCommand();
    if (method_exists($command, 'getAnnotationData')) {
      /* @var \Consolidation\AnnotatedCommand\AnnotationData */
      $annotation_data = $event->getCommand()->getAnnotationData();
      if ($annotation_data->has('executeInDrupalVm') && $this->shouldExecuteInDrupalVm()) {
        $event->disableCommand();
        $command = $event->getCommand();
        $new_input = $this->createCommandInputFromCurrentParams($command, $event->getInput());
        $defines = $new_input->getOption('define');
        $defines[] = 'drush.alias=self';
        $new_input->setOption('define', $defines);
        // We cannot return an exit code directly, because disabled commands
        // always return ConsoleCommandEvent::RETURN_CODE_DISABLED.
        $command_string = $this->convertInputToCommandString($new_input, $command);
        $result = $this->executeCommandInDrupalVm($command_string);
      }
    }
  }

  /**
   * Indicates whether a frontend hook should be invoked inside of Drupal VM.
   *
   * @return bool
   *   TRUE if it should be invoked inside of  Drupal VM.
   */
  protected function shouldExecuteInDrupalVm() {
    return !$this->getInspector()->isVmCli() &&
      $this->getInspector()->isDrupalVmLocallyInitialized()
      && $this->getInspector()->isDrupalVmBooted();
  }

  /**
   * @param \Symfony\Component\Console\Input\ArrayInput $new_input
   * @param \Symfony\Component\Console\Command\Command $command
   *   The command.
   *
   * @return string
   */
  protected function convertInputToCommandString(ArrayInput $new_input, Command $command) {
    $command_definition = $command->getDefinition();
    $command_string = (string) $new_input;
    foreach ($new_input->getOptions() as $name => $value) {
      if ($new_input->getOption($name)) {
        if ($command_definition->getOption($name)->acceptValue()) {
          if (gettype($value) === 'string') {
            $command_string .= " --$name=$value";
          }
          else {
            foreach ($value as $sub_value) {
              $command_string .= " --$name=$sub_value";
            }
          }
        }
        else {
          $command_string .= " --$name";
        }
      }
    }
    return $command_string;
  }

  /**
   * Creates ArrayInput for new command using valid params from current one.
   *
   * @param \Symfony\Component\Console\Command\Command $command
   *   The command.
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input from the ConsoleCommandEvent.
   *
   * @return \Symfony\Component\Console\Input\ArrayInput
   */
  protected function createCommandInputFromCurrentParams(Command $command, InputInterface $input) {
    $command_definition = $command->getDefinition();
    $command_name = $command->getName();
    $options = $this->input->getOptions();
    $args = $this->input->getArguments();
    unset($args['command']);
    // Filter out any invalid arguments.
    foreach ($args as $name => $value) {
      if (is_null($value) || !$command_definition->hasArgument($name)) {
        unset($args[$name]);
      }
    }
    // Pass in all the arguments so that ArrayInput constructor will not be
    // missing necessary arguments in validation.
    $new_input = new ArrayInput(array_merge(['blt', 'command' => $command_name], $args),
      $command_definition);
    foreach ($options as $name => $value) {
      if ($command_definition->hasOption($name)) {
        $new_input->setOption($name, $value);
      }
    }
    return $new_input;
  }

}
