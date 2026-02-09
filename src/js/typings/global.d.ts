/**
 * @file
 * This file is only used to provide typings and interfaces and is not output
 * as javascript.
 */

declare var displace:any;

declare function once(id: string, selector: string, context?: HTMLElement): Array<HTMLElement>;

declare function debounce(func: Function, wait?: number, immediate?: boolean): Function;

interface JQueryAutocompleteInstance {
  option(optionName: string, value: any): void;
  widget(): JQuery;
}

interface JQueryUIPosition {
  my: string;
  at: string;
  using?: (position: { top: number; left: number }, feedback: any) => void;
}

interface JQuery {
  findOnce:any;
  overlaps:any;
  drupalSetSummary:any;
  autocomplete(methodName: string): JQueryAutocompleteInstance | undefined;
  autocomplete(methodName: string, option: string, value: any): void;
  autocomplete(methodName: 'widget'): JQuery | undefined;
}

interface Window {
  tabbable:any;
}
