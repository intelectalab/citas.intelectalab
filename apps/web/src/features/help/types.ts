import type { Vocabulary } from '@/hooks/useVocabulary';

export interface HelpNeeds {
  perm?: string;
  module?: string;
  admin?: boolean;
}

export interface HelpPoint extends HelpNeeds {
  title: string;
  text: string;
}

export type HelpStep = string | (HelpNeeds & { text: string });

export interface HelpTask extends HelpNeeds {
  title: string;
  steps: HelpStep[];
}

export interface HelpRule extends HelpNeeds {
  title: string;
  text: string;
  where?: string;
}

export interface HelpTopic {
  summary: string;
  points?: HelpPoint[];
  tasks?: HelpTask[];
  rules?: HelpRule[];
}

export type HelpTopics = (vocab: Vocabulary) => Record<string, HelpTopic>;

export const lower = (word: string) => word.toLowerCase();
