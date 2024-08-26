declare namespace drupal {

  export namespace Core {

    export interface IJQueryAjaxSettingsExtra extends JQueryAjaxSettings {

      extraData?: any;

    }

    export interface IAjaxEffect {

      showEffect: string;

      hideEffect: string;

      showSpeed: string | number;

    }

    export interface IAjaxError extends Error {

      new (
        xmlHttpRequest: XMLHttpRequest,
        uri: string,
        customMessage?: string
      ): IAjaxError;

      constructor: IAjaxError;

    }

    export interface IAjaxElementSettings {

      selector?: string;

      element?: HTMLElement;

      base?: string;

      url?: string;

      event?: string;

      method?: string;

      // @todo Remove any.
      dialogType?: any;

      // @todo Remove any.
      dialog?: any;

      progress?: Core.IAjaxProgressSettings;

      setClick?: boolean;

    }

    export interface IAjaxProgressSettings {

      type: string;

    }

    export interface IAjax {

      new (
        base: string,
        element: HTMLElement,
        elementSettings: IAjaxElementSettings
      ): IAjax;

      AJAX_REQUEST_PARAMETER: string;

      commands: IAjaxCommands;

      instanceIndex: boolean;

      wrapper: string;

      element: HTMLElement;

      element_settings: IAjaxElementSettings;

      $form?: JQuery;

      url?: string;

      options: Core.IJQueryAjaxSettingsExtra;

      // @todo Remove any.
      settings?: any;

      ajaxing?: boolean;

      execute(): void;

      keypressResponse(
        element: HTMLElement,
        event: JQueryEventObject
      ): void;

      eventResponse(
        element: HTMLElement,
        event: JQueryEventObject
      ): void;

      beforeSerialize(
        element: HTMLElement,
        options: Core.IJQueryAjaxSettingsExtra
      ): void;

      beforeSubmit(
        formValues: any,
        element: HTMLElement,
        options: Core.IJQueryAjaxSettingsExtra
      ): void;

      beforeSend(
        xmlHttpRequest: XMLHttpRequest,
        options: Core.IJQueryAjaxSettingsExtra
      ): void;

      setProgressIndicatorBar(): void;

      setProgressIndicatorThrobber(): void;

      setProgressIndicatorFullscreen(): void;

      // @todo Remove any.
      success(
        response: IAjaxCommand[],
        status: any
      ): void;

      getEffect(response: IAjaxCommand): IAjaxEffect;

      error(
        xmlHttpRequest: XMLHttpRequest,
        uri: string,
        customMessage: string
      ): void;

    }

    export interface IAjaxCommand {
      (
        ajax: IAjax,
        response: XMLHttpRequest,
        status: any
      ): void;

      command: string;

      method?: string;

      selector?: string;

      data?: string;

      settings?: any;

      asterisk?: boolean;

      text?: string;

      title?: string;

      url?: string;

      argument?: any;

      name?: string;

      value?: string;

      old?: string;

      'new'?: string;

      merge?: boolean;

      args?: any[];

      effect?: string;

      speed?: string | number;
    }

    export interface IAjaxCommands {

      new (): IAjaxCommands;

      insert: IAjaxCommand;

      remove: IAjaxCommand;

      changed: IAjaxCommand;

      alert: IAjaxCommand;

      redirect: IAjaxCommand;

      css: IAjaxCommand;

      settings: IAjaxCommand;

      data: IAjaxCommand;

      invoke: IAjaxCommand;

      restripe: IAjaxCommand;

      update_build_id: IAjaxCommand;

      add_css: IAjaxCommand;

    }

    export interface Iajax {

      new (settings: Core.IAjaxElementSettings): IAjax;

      (elementSettings: Core.IAjaxElementSettings): void;

      instances: IAjax[];

      WRAPPER_FORMAT: string;

    }

    export interface IBehaviors {

      /**
       * Attaches the Ajax behavior to each Ajax form element.
       */
      AJAX?: IBehavior;

    }

  }

  export interface IDrupalSettings {

    ajax: {[key: string]: Core.IAjaxElementSettings};

  }

  export interface IDrupalStatic {

    Ajax?: Core.IAjax;

    AjaxCommands?: Core.IAjaxCommands;

    AjaxError?: Core.IAjaxError;

    ajax: Core.Iajax;

  }

}
