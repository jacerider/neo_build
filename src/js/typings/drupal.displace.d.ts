declare namespace drupal {

  export namespace Core {

    export interface IDisplace {

      new (): IDisplace;

      (broadcast: boolean): IOffsets;

      offsets: IOffsets;

      calculateOffset?(edge: string): number;

    }

    export interface IBehaviors {

      drupalDisplace?: IBehavior;

    }

  }

  export interface IDrupalStatic {

    displace?: Core.IDisplace;

  }
}
