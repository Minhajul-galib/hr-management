<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { CheckCircle, ArrowDown, FileText, Users, Calculator, Shield, UserCheck, Briefcase, DollarSign } from 'lucide-vue-next';

const flowSteps = [
    {
        id: 1,
        title: 'Purchase Department',
        subtitle: 'Document Creation',
        description: 'Purchase department creates and submits the document',
        icon: FileText,
        color: 'blue',
        action: 'Submit'
    },
    {
        id: 2,
        title: 'Head of Department',
        subtitle: 'Initial Review',
        description: 'Department head reviews and approves',
        icon: Users,
        color: 'indigo',
        action: 'Approve'
    },
    {
        id: 3,
        title: 'Account Department',
        subtitle: 'Financial Review',
        description: 'Account department verifies financial details',
        icon: Calculator,
        color: 'purple',
        action: 'Verify'
    },
    {
        id: 4,
        title: 'Audit',
        subtitle: 'Audit Check',
        description: 'Audit team reviews for compliance',
        icon: Shield,
        color: 'pink',
        action: 'Audit'
    },
    {
        id: 5,
        title: 'DGM Sir',
        subtitle: 'Deputy General Manager',
        description: 'DGM reviews and approves',
        icon: UserCheck,
        color: 'red',
        action: 'Approve'
    },
    {
        id: 6,
        title: 'GM Sir',
        subtitle: 'General Manager',
        description: 'GM final approval',
        icon: Briefcase,
        color: 'orange',
        action: 'Approve'
    },
    {
        id: 7,
        title: 'CEO SIR DESK',
        subtitle: 'Audit of CEO',
        description: 'Mr. Muzahid check the document',
        icon: Users,
        color: 'yellow',
        action: 'Process'
    },
    {
        id: 8,
        title: 'CEO',
        subtitle: 'Approval of CEO',
        description: 'CEO final authorization',
        icon: Briefcase,
        color: 'green',
        action: 'Authorize'
    },
    {
        id: 9,
        title: 'Account Department',
        subtitle: 'Voucher Attachment',
        description: 'Account attaches voucher to document',
        icon: DollarSign,
        color: 'teal',
        action: 'Attach Voucher'
    },
    {
        id: 10,
        title: 'Audit',
        subtitle: 'Voucher Audit',
        description: 'Audit team second review',
        icon: Shield,
        color: 'cyan',
        action: 'Re-Audit'
    },
    {
        id: 11,
        title: 'DGM Sir',
        subtitle: 'Voucher Review',
        description: 'DGM second approval',
        icon: UserCheck,
        color: 'sky',
        action: 'Re-Approve'
    },
    {
        id: 12,
        title: 'GM Sir',
        subtitle: 'Voucher Review',
        description: 'GM final review',
        icon: Briefcase,
        color: 'blue',
        action: 'Final Approve'
    },
    {
        id: 13,
        title: 'Minhaz Sir',
        subtitle: 'Final Authorization',
        description: 'Minhaz sir final check',
        icon: UserCheck,
        color: 'indigo',
        action: 'Final Check'
    },
    {
        id: 14,
        title: 'Check Making',
        subtitle: 'Payment Processing',
        description: 'Check is prepared and issued',
        icon: CheckCircle,
        color: 'green',
        action: 'Issue Check'
    }
];

const getColorClasses = (color) => {
    const colors = {
        blue: 'bg-blue-100 border-blue-500 text-blue-700',
        indigo: 'bg-indigo-100 border-indigo-500 text-indigo-700',
        purple: 'bg-purple-100 border-purple-500 text-purple-700',
        pink: 'bg-pink-100 border-pink-500 text-pink-700',
        red: 'bg-red-100 border-red-500 text-red-700',
        orange: 'bg-orange-100 border-orange-500 text-orange-700',
        yellow: 'bg-yellow-100 border-yellow-500 text-yellow-700',
        green: 'bg-green-100 border-green-500 text-green-700',
        teal: 'bg-teal-100 border-teal-500 text-teal-700',
        cyan: 'bg-cyan-100 border-cyan-500 text-cyan-700',
        sky: 'bg-sky-100 border-sky-500 text-sky-700',
    };
    return colors[color] || colors.blue;
};

const getIconBgColor = (color) => {
    const colors = {
        blue: 'bg-blue-500',
        indigo: 'bg-indigo-500',
        purple: 'bg-purple-500',
        pink: 'bg-pink-500',
        red: 'bg-red-500',
        orange: 'bg-orange-500',
        yellow: 'bg-yellow-500',
        green: 'bg-green-500',
        teal: 'bg-teal-500',
        cyan: 'bg-cyan-500',
        sky: 'bg-sky-500',
    };
    return colors[color] || colors.blue;
};
</script>

<template>
    <Head title="Document Approval Process Flow" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Document Approval Process Flow</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl sm:px-4 lg:px-4">
                <!-- Header Info -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Purchase Document Approval Workflow</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        This flowchart shows the complete approval process for purchase documents from creation to check issuance.
                        Each step requires approval from the designated authority before moving to the next stage.
                    </p>
                </div>

                <!-- Flowchart -->
                <div class="space-y-4">
                    <div v-for="(step, index) in flowSteps" :key="step.id" class="relative">
                        <!-- Step Card -->
                        <div
                            :class="getColorClasses(step.color)"
                            class="bg-white dark:bg-gray-800 overflow-hidden shadow-lg sm:rounded-lg border-l-4 transform transition-all duration-300 hover:scale-105 hover:shadow-xl"
                        >
                            <div class="p-6">
                                <div class="flex items-start">
                                    <!-- Step Number & Icon -->
                                    <div class="flex-shrink-0">
                                        <div :class="getIconBgColor(step.color)" class="w-16 h-16 rounded-full flex items-center justify-center text-white shadow-lg">
                                            <component :is="step.icon" :size="28" />
                                        </div>
                                        <div class="mt-2 text-center">
                                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm font-bold">
                                                {{ step.id }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Step Content -->
                                    <div class="ml-6 flex-1">
                                        <div class="flex items-start justify-between">
                                            <div>
                                                <h4 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ step.title }}</h4>
                                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400 mt-1">{{ step.subtitle }}</p>
                                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">{{ step.description }}</p>
                                            </div>
                                            <div class="ml-4">
                                                <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold bg-white dark:bg-gray-700 shadow-md border-2" :class="`border-${step.color}-500 text-${step.color}-700`">
                                                    {{ step.action }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Arrow -->
                        <div v-if="index < flowSteps.length - 1" class="flex justify-center my-4">
                            <div class="flex flex-col items-center">
                                <ArrowDown :size="32" class="text-gray-400 animate-bounce" />
                                <div class="w-1 h-8 bg-gradient-to-b from-gray-300 to-transparent"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Completion Badge -->
                <div class="mt-8 bg-gradient-to-r from-green-50 to-emerald-50 border-2 border-green-500 rounded-lg p-6 text-center">
                    <CheckCircle :size="48" class="mx-auto text-green-600 mb-3" />
                    <h3 class="text-2xl font-bold text-green-800 mb-2">Process Complete!</h3>
                    <p class="text-green-700">Check has been issued and the payment process is complete.</p>
                </div>

                <!-- Legend -->
                <div class="mt-8 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Process Legend</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-blue-500 rounded mr-3"></div>
                            <span class="text-sm text-gray-700 dark:text-gray-300">Initial Submission & Review</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-purple-500 rounded mr-3"></div>
                            <span class="text-sm text-gray-700 dark:text-gray-300">Financial & Audit Review</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-red-500 rounded mr-3"></div>
                            <span class="text-sm text-gray-700 dark:text-gray-300">Management Approval</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-green-500 rounded mr-3"></div>
                            <span class="text-sm text-gray-700 dark:text-gray-300">Final Authorization & Payment</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
@keyframes bounce {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-10px);
    }
}

.animate-bounce {
    animation: bounce 2s infinite;
}
</style>
