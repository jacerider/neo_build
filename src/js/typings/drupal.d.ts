declare namespace drupal {

  export namespace Core {

    export type ListStr<T> = {
      [key: string]: T;
    }

    export type ListNum<T> = {
      [key: number]: T;
    }

    export interface IBehavior {
      attach(
        context?: HTMLElement,
        settings?: IDrupalSettings
      ): void;
      detach?(
        context?: HTMLElement,
        settings?: IDrupalSettings,
        trigger?: string
      ): void;
    }

    export interface IBehaviors {
      [key: string]: any;
    }

    export interface IAjaxError extends Error {
      new (
        xmlHttpRequest: XMLHttpRequest,
        uri: string,
        customMessage?: string
      ): IAjaxError;
      constructor: IAjaxError;
    }

    export interface IAjaxCommand {
      (
        ajax: IAjax,
        response: any,
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

    export interface ITranslationOptions {
      context?: string;
    }

    export interface IPlaceholders {
      [key: string]: string;
    }

    export interface ITheme {
      (func: 'placeholder', str: string): string;
      (func: string, ...params: any[]): string;
      [key: string]: (func: any, ...params: any[]) => string;
    }

    export interface IPoint {
      x: number;
      y: number;
    }

    export interface IRange {
      min: number;
      max: number;
    }

    export interface IOffsets {
      top: number;
      right: number;
      bottom: number;
      left: number;
    }

    export interface IUrlGenerator {
      (path: string): string;
      toAbsolute(url: string): string;
      isLocal(url: string): boolean;
    }

    export interface ILocale {

    }
  }

  export interface IDrupalSettings {
    // @todo Move into an Interface.
    path: {
      baseUrl: string;
      currentLanguage: string;
      currentPath: string;
      currentPathIsAdmin: string;
      isFront: boolean;
      pathPrefix: string;
      scriptPath: string;
    };
    pluralDelimiter: string;
    // @todo Move into an Interface.
    user: {
      uid: number;
      permissionsHash: string;
    };
    [key: string]: any;
  }

  export interface IDrupalStatic {

    attachBehaviors(
      context: HTMLElement,
      settings: IDrupalSettings
    ): void;

    detachBehaviors(
      context?: HTMLElement,
      settings?: IDrupalSettings,
      trigger?: string
    ): void;

    behaviors: Core.IBehaviors;

    AjaxCommands?: Core.IAjaxCommands;

    AjaxError?: Core.IAjaxError;

    locale: Core.ILocale;

    checkPlain(text: string): string;

    elementIsVisible(element: HTMLElement): boolean;

    // @todo Remove any.
    checkWidthBreakpoint?(width: number): any;

    // @todo Remove any.
    encodePath(item: any): any;

    formatPlural(
      count: number,
      singular: string,
      plural: string,
      args?: Core.IPlaceholders,
      options?: Core.ITranslationOptions
    ): string;

    formatString(
        str: string,
      args: Core.IPlaceholders
    ): string;

    stringReplace(
      str: string,
      args?: Core.IPlaceholders,
      keys?: string[]
    ): string;

    t(
      str: string,
      args?: Core.IPlaceholders,
      options?: Core.ITranslationOptions
    ): string;

    theme: Core.ITheme;

    throwError(error: Error): void;

    url: Core.IUrlGenerator;

    debounce(
      func: Function,
      wait: number,
      immediate?: boolean
    ): Function;
  }
}

/* tslint:disable:interface-name */
interface Window {
  Drupal?: drupal.IDrupalStatic;
  drupalSettings?: drupal.IDrupalSettings;
}

/* tslint:enable:interface-name */
declare var drupalSettings: drupal.IDrupalSettings;
declare var Drupal: drupal.IDrupalStatic;
